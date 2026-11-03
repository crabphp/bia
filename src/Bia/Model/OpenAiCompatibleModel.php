<?php

declare(strict_types=1);

namespace Bia\Model;

use Bia\Contracts\Message;
use Bia\Contracts\Model;
use Bia\Contracts\ModelEvent;
use Bia\Contracts\ModelRequest;
use Bia\Contracts\ModelResult;
use Bia\Contracts\ToolCall;
use Bia\Contracts\ToolDefinition;
use Crab\Contracts\HttpClient\Client;
use Crab\Engine\Async\Cancellation;

/**
 * Streams Chat Completions-compatible responses, including tdaproxy and other
 * providers that expose an OpenAI-compatible endpoint. This is the only
 * place that knows the Chat Completions wire format.
 */
final readonly class OpenAiCompatibleModel implements Model
{
    public function __construct(
        private Client $http,
        private string $baseUrl,
        private string $apiKey,
        private string $defaultModel,
        private string $endpoint = 'chat/completions',
        private float $timeout = 3600.0,
        private ?float $idleTimeout = 120.0,
        private bool $streamUsage = true,
    ) {
        if ($this->apiKey === '') {
            throw new \InvalidArgumentException('An API key is required for the model provider.');
        }
        if (!filter_var($this->baseUrl, FILTER_VALIDATE_URL)) {
            throw new \InvalidArgumentException('The model base URL must be an absolute URL.');
        }
        if ($this->endpoint === '' || str_contains($this->endpoint, '?') || str_contains($this->endpoint, '#')) {
            throw new \InvalidArgumentException('The model endpoint must be a relative path.');
        }
        if (!is_finite($this->timeout) || $this->timeout <= 0) {
            throw new \InvalidArgumentException('The model timeout must be finite and positive.');
        }
        if ($this->idleTimeout !== null && (!is_finite($this->idleTimeout) || $this->idleTimeout <= 0)) {
            throw new \InvalidArgumentException('The model idle timeout must be finite and positive, or null.');
        }
    }

    public function stream(
        ModelRequest $request,
        callable $emit,
        ?Cancellation $cancellation = null,
    ): ModelResult {
        $payload = [
            'model' => $request->model !== '' ? $request->model : $this->defaultModel,
            'messages' => self::messages($request->messages, $request->reasoning),
            'stream' => true,
        ];
        if ($this->streamUsage && str_ends_with(rtrim($this->endpoint, '/'), 'chat/completions')) {
            // Chat Completions only reports usage on a stream when asked.
            $payload['stream_options'] = ['include_usage' => true];
        }
        if ($request->tools !== []) {
            $payload['tools'] = array_map(static fn (ToolDefinition $tool): array => $tool->openAi(), $request->tools);
        }
        foreach ($request->options as $key => $value) {
            $payload[$key] = $value;
        }

        $response = $this->http
            ->timeout($this->timeout)
            ->stream(
                'POST',
                rtrim($this->baseUrl, '/') . '/' . ltrim($this->endpoint, '/'),
                headers: [
                    'Authorization' => 'Bearer ' . $this->apiKey,
                    'Accept' => 'text/event-stream',
                ],
                json: $payload,
                cancellation: $cancellation,
                idle: $this->idleTimeout,
            );
        if ($response->getStatusCode() >= 400) {
            throw ModelException::fromAnswer($response->getStatusCode(), (string) $response->getBody(), $response->getHeaderLine('retry-after'));
        }

        $contentType = strtolower($response->getHeaderLine('content-type'));
        if (!str_contains($contentType, 'text/event-stream')) {
            $data = $response->json();
            if (!is_array($data)) {
                throw new \RuntimeException('The model returned a response that was not JSON.');
            }
            $text = self::text($data);
            if ($text !== '') {
                $emit(ModelEvent::text($text));
            }
            [$reasoning, $field] = self::reasoning($data);
            $usage = self::usage($data['usage'] ?? null);
            if ($usage !== []) {
                $emit(ModelEvent::usage($usage));
            }
            $finishReason = is_string($data['choices'][0]['finish_reason'] ?? null) ? $data['choices'][0]['finish_reason'] : 'stop';
            $toolCalls = self::toolCalls($data);
            foreach ($toolCalls as $toolCall) {
                $emit(ModelEvent::toolCall($toolCall->toArray()));
            }
            $emit(ModelEvent::completed(['finish_reason' => $finishReason, 'usage' => $usage]));
            return new ModelResult($text, $finishReason, $usage, $toolCalls, $data, $reasoning, reasoningField: $reasoning !== '' ? $field : '');
        }

        $text = '';
        $reasoning = '';
        $field = '';
        $usage = [];
        $finishReason = 'stop';
        $raw = [];
        $toolBuffers = [];
        foreach ($response->events() as $event) {
            $cancellation?->throwIfCancelled();
            if ($event->data === '[DONE]') {
                break;
            }
            $data = json_decode($event->data, true);
            if (!is_array($data)) {
                continue;
            }
            if (isset($data['error'])) {
                throw ModelException::fromAnswer(500, $event->data);
            }
            $raw = $data;
            $delta = self::text($data);
            if ($delta !== '') {
                $text .= $delta;
                $emit(ModelEvent::text($delta));
            }
            [$thought, $from] = self::reasoning($data);
            if ($thought !== '') {
                $field = $from;
                $reasoning .= $thought;
                $emit(ModelEvent::reasoning($thought));
            }
            self::collectToolCalls($data, $toolBuffers);
            if (isset($data['usage']) && is_array($data['usage'])) {
                $usage = self::usage($data['usage']);
                $emit(ModelEvent::usage($usage));
            }
            $reason = $data['choices'][0]['finish_reason'] ?? null;
            if (is_string($reason) && $reason !== '') {
                $finishReason = $reason;
            }
        }
        $toolCalls = array_map(
            static fn (array $buffer): ToolCall => self::call($buffer['id'], $buffer['name'], $buffer['arguments']),
            array_values($toolBuffers),
        );
        foreach ($toolCalls as $toolCall) {
            $emit(ModelEvent::toolCall($toolCall->toArray()));
        }
        $emit(ModelEvent::completed(['finish_reason' => $finishReason, 'usage' => $usage]));
        return new ModelResult($text, $finishReason, $usage, $toolCalls, $raw, $reasoning, reasoningField: $field);
    }

    /**
     * Neutral messages in the Chat Completions format. A reply's reasoning
     * goes back in the field it came in, as DeepSeek requires once tools are
     * in play, and stays out where none was sent in a field of its own (see
     * ModelRequest::$reasoning for the other ways).
     *
     * @param list<Message> $messages
     * @return list<array<string, mixed>>
     */
    public static function messages(array $messages, string $reasoning = 'stored'): array
    {
        $wire = [];
        foreach ($messages as $message) {
            if ($message->role === 'tool') {
                $wire[] = ['role' => 'tool', 'tool_call_id' => (string) $message->toolCallId, 'content' => $message->text];
                continue;
            }
            $item = ['role' => $message->role, 'content' => $message->text];
            if ($message->images !== []) {
                // Text and images as parts; an image whose file is gone is said so, in words.
                $parts = $message->text !== '' ? [['type' => 'text', 'text' => $message->text]] : [];
                foreach ($message->images as $image) {
                    $url = $image->dataUrl();
                    $parts[] = $url !== null
                        ? ['type' => 'image_url', 'image_url' => ['url' => $url]]
                        : ['type' => 'text', 'text' => '[an image no longer kept]'];
                }
                $item['content'] = $parts;
            }
            if ($message->role === 'assistant' && $message->toolCalls !== []) {
                $item['content'] = $message->text === '' ? null : $message->text;
                $item['tool_calls'] = array_map(static fn (ToolCall $call): array => [
                    'id' => $call->id,
                    'type' => 'function',
                    'function' => [
                        'name' => $call->name,
                        // An empty PHP array is a JSON list; arguments are always an object.
                        'arguments' => $call->error !== null || $call->arguments === []
                            ? '{}'
                            : json_encode($call->arguments, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    ],
                ], $message->toolCalls);
            }
            if ($message->role === 'assistant' && $reasoning !== 'none') {
                $field = (string) ($message->meta['reasoning_field'] ?? '');
                if ($field !== '' && $message->reasoning !== '') {
                    $item[$field] = $message->reasoning;
                } elseif ($reasoning === 'all') {
                    $item['reasoning_content'] = $message->reasoning;
                }
            }
            $wire[] = $item;
        }
        return $wire;
    }

    /**
     * Extracts text from Chat Completions, Responses-style, and simple proxy
     * event payloads.
     *
     * @param array<string, mixed> $data
     */
    private static function text(array $data): string
    {
        if (isset($data['choices'])) {
            $value = $data['choices'][0]['delta']['content'] ?? $data['choices'][0]['message']['content'] ?? '';
        } elseif (isset($data['type'])) {
            // Responses-style events: only output text is answer text, not argument or reasoning deltas.
            $value = $data['type'] === 'response.output_text.delta' ? ($data['delta'] ?? '') : '';
        } else {
            $value = $data['text'] ?? '';
        }
        return is_string($value) ? $value : '';
    }

    /**
     * Reasoning text, as DeepSeek, vLLM and OpenRouter stream it, and the
     * field it came in.
     *
     * @param array<string, mixed> $data
     * @return array{string, string}
     */
    private static function reasoning(array $data): array
    {
        $part = $data['choices'][0]['delta'] ?? $data['choices'][0]['message'] ?? [];
        foreach (['reasoning_content', 'reasoning'] as $field) {
            if (is_array($part) && is_string($part[$field] ?? null) && $part[$field] !== '') {
                return [$part[$field], $field];
            }
        }
        return ['', ''];
    }

    /**
     * @return array{input?: int, output?: int, cache_read?: int, total?: int}
     */
    private static function usage(mixed $usage): array
    {
        if (!is_array($usage)) {
            return [];
        }
        $normalized = array_filter([
            'input' => $usage['prompt_tokens'] ?? $usage['input_tokens'] ?? null,
            'output' => $usage['completion_tokens'] ?? $usage['output_tokens'] ?? null,
            'cache_read' => $usage['prompt_tokens_details']['cached_tokens'] ?? $usage['input_tokens_details']['cached_tokens'] ?? null,
            'total' => $usage['total_tokens'] ?? null,
        ], static fn (mixed $value): bool => is_int($value));
        if (!isset($normalized['total']) && isset($normalized['input'], $normalized['output'])) {
            $normalized['total'] = $normalized['input'] + $normalized['output'];
        }
        return $normalized;
    }

    /**
     * @param array<string, mixed> $data
     * @return list<ToolCall>
     */
    private static function toolCalls(array $data): array
    {
        $calls = $data['choices'][0]['message']['tool_calls'] ?? $data['tool_calls'] ?? [];
        if (!is_array($calls)) {
            return [];
        }
        $result = [];
        foreach ($calls as $index => $call) {
            if (!is_array($call)) {
                continue;
            }
            $function = is_array($call['function'] ?? null) ? $call['function'] : $call;
            $name = $function['name'] ?? '';
            if (!is_string($name)) {
                continue;
            }
            $result[] = self::call((string) ($call['id'] ?? "tool_{$index}"), $name, $function['arguments'] ?? []);
        }
        return $result;
    }

    /**
     * One call. Arguments that are not a JSON object, such as those of a
     * reply cut off at its length limit, become an error for the model to
     * see instead of the tool running with none.
     */
    private static function call(string $id, string $name, mixed $arguments): ToolCall
    {
        if (is_string($arguments)) {
            $arguments = trim($arguments) === '' ? [] : json_decode($arguments, true);
        }
        if (is_array($arguments) && (!array_is_list($arguments) || $arguments === [])) {
            return new ToolCall($id, $name, $arguments);
        }
        return new ToolCall(
            $id,
            $name,
            [],
            "The arguments for {$name} were not a valid JSON object; send the call again with complete arguments.",
        );
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, array{id: string, name: string, arguments: string}> $buffers
     */
    private static function collectToolCalls(array $data, array &$buffers): void
    {
        $calls = $data['choices'][0]['delta']['tool_calls'] ?? [];
        if (!is_array($calls)) {
            return;
        }
        foreach ($calls as $index => $call) {
            if (!is_array($call)) {
                continue;
            }
            $key = (string) ($call['index'] ?? $index);
            $function = is_array($call['function'] ?? null) ? $call['function'] : [];
            $buffers[$key] ??= ['id' => (string) ($call['id'] ?? "tool_{$key}"), 'name' => '', 'arguments' => ''];
            if (isset($call['id']) && is_string($call['id'])) {
                $buffers[$key]['id'] = $call['id'];
            }
            if (isset($function['name']) && is_string($function['name'])) {
                $buffers[$key]['name'] .= $function['name'];
            }
            if (isset($function['arguments']) && is_string($function['arguments'])) {
                $buffers[$key]['arguments'] .= $function['arguments'];
            }
        }
    }
}
