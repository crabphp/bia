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
 * Streams OpenAI-compatible Chat Completions or Responses replies.
 *
 * The model contract stays provider-neutral. This adapter is the only place
 * that knows either wire format, which lets a provider such as tdaproxy use
 * the same Responses transport as Codex while other providers keep Chat
 * Completions.
 */
final readonly class OpenAiCompatibleModel implements Model
{
    public const CHAT = 'chat';
    public const RESPONSES = 'responses';

    public function __construct(
        private Client $http,
        private string $baseUrl,
        private string $apiKey,
        private string $defaultModel,
        private string $endpoint = 'chat/completions',
        private float $timeout = 3600.0,
        private ?float $idleTimeout = 120.0,
        private bool $streamUsage = true,
        private string $wireApi = self::CHAT,
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
        if (!in_array($this->wireApi, [self::CHAT, self::RESPONSES], true)) {
            throw new \InvalidArgumentException('The model wire API must be chat or responses.');
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
        $payload = $this->wireApi === self::RESPONSES
            ? self::responsesPayload($request, $this->defaultModel)
            : [
                'model' => $request->model !== '' ? $request->model : $this->defaultModel,
                'messages' => self::messages($request->messages, $request->reasoning),
                'stream' => true,
            ];
        if ($this->wireApi === self::CHAT && $this->streamUsage && str_ends_with(rtrim($this->endpoint, '/'), 'chat/completions')) {
            // Chat Completions only reports usage on a stream when asked.
            $payload['stream_options'] = ['include_usage' => true];
        }
        if ($this->wireApi === self::CHAT && $request->tools !== []) {
            $payload['tools'] = array_map(static fn (ToolDefinition $tool): array => $tool->openAi(), $request->tools);
        }
        if ($this->wireApi === self::CHAT) {
            foreach ($request->options as $key => $value) {
                $payload[$key] = $value;
            }
        }

        $response = $this->http
            ->timeout($this->timeout)
            ->stream(
                'POST',
                rtrim($this->baseUrl, '/') . '/' . ltrim($this->requestEndpoint(), '/'),
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

        if ($this->wireApi === self::RESPONSES) {
            return $this->streamResponses($response, $emit, $cancellation);
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
     * The URL path for the selected wire API. A provider may keep Bia's
     * legacy endpoint default while opting into Responses, just as the
     * Codex provider config does with `wire_api = "responses"`.
     */
    private function requestEndpoint(): string
    {
        $endpoint = rtrim($this->endpoint, '/');
        if ($this->wireApi === self::RESPONSES && ($endpoint === 'chat/completions' || str_ends_with($endpoint, '/chat/completions'))) {
            return substr($endpoint, 0, -strlen('chat/completions')) . 'responses';
        }
        return $this->endpoint;
    }

    /**
     * Builds an OpenAI Responses request from Bia's neutral request.
     *
     * @return array<string, mixed>
     */
    private static function responsesPayload(ModelRequest $request, string $defaultModel): array
    {
        $payload = [
            'model' => $request->model !== '' ? $request->model : $defaultModel,
            'input' => self::responsesInput($request->messages),
            'stream' => true,
            // Bia replays its own conversation history. Stateless Responses
            // calls return encrypted reasoning items that can safely be
            // replayed on the next tool turn instead of relying on a remote
            // previous_response_id.
            'store' => false,
        ];
        if ($request->tools !== []) {
            $payload['tools'] = array_map(static function (ToolDefinition $tool): array {
                $function = $tool->openAi()['function'];
                return [
                    'type' => 'function',
                    'name' => $function['name'],
                    'description' => $function['description'],
                    // Keep optional MCP fields optional. Some compatible
                    // Responses gateways treat an omitted strict flag as
                    // strict mode, which makes models materialize every
                    // optional object. Codex sends this explicit false.
                    'strict' => false,
                    'parameters' => $function['parameters'],
                ];
            }, $request->tools);
        }
        foreach ($request->options as $key => $value) {
            if ($key === 'reasoning_effort') {
                // Responses nests the effort. `none` and `default` mean no
                // reasoning override, so leaving the field out is correct.
                if (is_string($value) && $value !== '' && !in_array($value, ['none', 'default'], true)) {
                    $payload['reasoning'] = ['effort' => $value];
                }
                continue;
            }
            $payload[$key] = $value;
        }
        return $payload;
    }

    /**
     * Neutral conversation items accepted by the Responses API.
     *
     * @param list<Message> $messages
     * @return list<array<string, mixed>>
     */
    public static function responsesInput(array $messages): array
    {
        $input = [];
        foreach ($messages as $message) {
            if ($message->role === 'tool') {
                $input[] = [
                    'type' => 'function_call_output',
                    'call_id' => (string) $message->toolCallId,
                    'output' => $message->text,
                ];
                continue;
            }

            // Responses reasoning and function-call items must be replayed in
            // the exact order returned by the provider. The encrypted
            // reasoning item is what makes a subsequent tool turn valid for
            // Codex and other reasoning models.
            if ($message->role === 'assistant' && is_array($message->meta['responses_output'] ?? null)) {
                $output = array_values(array_filter($message->meta['responses_output'], 'is_array'));
                if ($output !== []) {
                    foreach ($output as $item) {
                        $input[] = $item;
                    }
                    continue;
                }
            }

            if ($message->role === 'assistant' && $message->toolCalls !== []) {
                if ($message->text !== '') {
                    $input[] = ['role' => 'assistant', 'content' => $message->text];
                }
                foreach ($message->toolCalls as $call) {
                    $input[] = [
                        'type' => 'function_call',
                        'call_id' => $call->id,
                        'name' => $call->name,
                        'arguments' => $call->error !== null || $call->arguments === []
                            ? '{}'
                            : json_encode($call->arguments, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    ];
                }
                continue;
            }

            $item = ['role' => $message->role, 'content' => $message->text];
            if ($message->images !== []) {
                $parts = $message->text !== '' ? [['type' => 'input_text', 'text' => $message->text]] : [];
                foreach ($message->images as $image) {
                    $url = $image->dataUrl();
                    $parts[] = $url !== null
                        ? ['type' => 'input_image', 'image_url' => $url, 'detail' => 'auto']
                        : ['type' => 'input_text', 'text' => '[an image no longer kept]'];
                }
                $item['content'] = $parts;
            }
            $input[] = $item;
        }
        return $input;
    }

    /**
     * Handles one Responses response, whether the server ignored streaming
     * and returned a JSON body.
     */
    private function responseJson(array $data, callable $emit): ModelResult
    {
        // Some compatible gateways wrap a non-streaming response in a
        // top-level `response` object, just as they do for a completed SSE
        // event. Normalize against the inner response while retaining the
        // original body as the raw result.
        $body = is_array($data['response'] ?? null) ? $data['response'] : $data;
        $text = self::responsesText($body);
        if ($text !== '') {
            $emit(ModelEvent::text($text));
        }
        [$reasoning, $field] = self::responsesReasoning($body);
        if ($reasoning !== '') {
            $emit(ModelEvent::reasoning($reasoning));
        }
        $usage = self::usage($body['usage'] ?? $data['usage'] ?? null);
        if ($usage !== []) {
            $emit(ModelEvent::usage($usage));
        }
        $toolCalls = self::responsesToolCalls($body);
        foreach ($toolCalls as $toolCall) {
            $emit(ModelEvent::toolCall($toolCall->toArray()));
        }
        $finishReason = self::responsesFinishReason($body);
        $emit(ModelEvent::completed(['finish_reason' => $finishReason, 'usage' => $usage]));
        $output = self::responsesOutput($body);
        if ($output !== []) {
            $data['_bia_responses_output'] = $output;
        }
        return new ModelResult($text, $finishReason, $usage, $toolCalls, $data, $reasoning, reasoningField: $field);
    }

    /**
     * Reads Responses SSE events and normalizes text, reasoning, tool calls,
     * usage and completion into Bia's model events.
     */
    private function streamResponses(mixed $response, callable $emit, ?Cancellation $cancellation): ModelResult
    {
        $contentType = strtolower($response->getHeaderLine('content-type'));
        if (!str_contains($contentType, 'text/event-stream')) {
            $data = $response->json();
            if (!is_array($data)) {
                throw new \RuntimeException('The model returned a response that was not JSON.');
            }
            return $this->responseJson($data, $emit);
        }

        $text = '';
        $reasoning = '';
        $field = '';
        $usage = [];
        $finishReason = 'stop';
        $raw = [];
        /** @var array<string, array<string, mixed>> $outputItems */
        $outputItems = [];
        /** @var array<string, array{id: string, name: string, arguments: string}> $toolBuffers */
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
            $raw = $data;
            $type = is_string($data['type'] ?? null) ? $data['type'] : $event->name;
            if (isset($data['error']) && $type !== 'response.failed') {
                throw ModelException::fromAnswer(500, $event->data);
            }
            if ($type === 'response.failed') {
                $message = self::responseError($data);
                throw new ModelException($message, 500);
            }

            if ($type === 'response.output_text.delta') {
                $delta = is_string($data['delta'] ?? null) ? $data['delta'] : '';
                if ($delta !== '') {
                    $text .= $delta;
                    $emit(ModelEvent::text($delta));
                }
            } elseif (in_array($type, ['response.reasoning_summary_text.delta', 'response.reasoning_text.delta', 'response.reasoning.delta'], true)) {
                $delta = is_string($data['delta'] ?? null) ? $data['delta'] : '';
                if ($delta !== '') {
                    $reasoning .= $delta;
                    $field = 'reasoning';
                    $emit(ModelEvent::reasoning($delta));
                }
            }

            self::collectResponsesTool($data, $type, $toolBuffers);
            if (isset($data['response']) && is_array($data['response'])) {
                self::collectResponsesOutput($data['response'], $toolBuffers);
                self::collectResponsesItems(self::responsesOutput($data['response']), $outputItems);
                $responseData = $data['response'];
                if ($usage === []) {
                    $usage = self::usage($responseData['usage'] ?? null);
                }
                if ($text === '' && ($completeText = self::responsesText($responseData)) !== '') {
                    $text = $completeText;
                    $emit(ModelEvent::text($completeText));
                }
                if ($reasoning === '' && ($completeReasoning = self::responsesReasoning($responseData)[0]) !== '') {
                    $reasoning = $completeReasoning;
                    $field = 'reasoning';
                    $emit(ModelEvent::reasoning($completeReasoning));
                }
                $finishReason = self::responsesFinishReason($responseData);
            }
            if ($type === 'response.output_item.done' && is_array($data['item'] ?? null)) {
                self::collectResponsesItems([$data['item']], $outputItems);
            }
            if (isset($data['usage']) && is_array($data['usage'])) {
                $usage = self::usage($data['usage']);
            }
            if (in_array($type, ['response.completed', 'response.incomplete', 'response.cancelled'], true)) {
                $finishReason = match ($type) {
                    'response.completed' => 'stop',
                    'response.incomplete' => 'length',
                    default => 'cancelled',
                };
            }
        }

        $toolCalls = array_map(
            static fn (array $buffer): ToolCall => self::call($buffer['id'], $buffer['name'], $buffer['arguments']),
            array_values($toolBuffers),
        );
        foreach ($toolCalls as $toolCall) {
            $emit(ModelEvent::toolCall($toolCall->toArray()));
        }
        if ($usage !== []) {
            $emit(ModelEvent::usage($usage));
        }
        $emit(ModelEvent::completed(['finish_reason' => $finishReason, 'usage' => $usage]));
        if ($outputItems !== []) {
            $raw['_bia_responses_output'] = array_values($outputItems);
        }
        return new ModelResult($text, $finishReason, $usage, $toolCalls, $raw, $reasoning, reasoningField: $field);
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function responseError(array $data): string
    {
        $error = $data['error'] ?? $data['response']['error'] ?? null;
        if (is_array($error) && is_string($error['message'] ?? null)) {
            return 'The model provider failed: ' . $error['message'];
        }
        return is_string($error) && $error !== '' ? 'The model provider failed: ' . $error : 'The model provider failed while streaming a response.';
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function responsesText(array $data): string
    {
        if (is_string($data['output_text'] ?? null)) {
            return $data['output_text'];
        }
        $output = $data['output'] ?? [];
        if (!is_array($output)) {
            return '';
        }
        $text = '';
        foreach ($output as $item) {
            if (!is_array($item) || ($item['type'] ?? '') !== 'message') {
                continue;
            }
            $content = $item['content'] ?? [];
            if (is_string($content)) {
                $text .= $content;
                continue;
            }
            if (!is_array($content)) {
                continue;
            }
            foreach ($content as $part) {
                if (is_array($part) && in_array($part['type'] ?? '', ['output_text', 'text'], true) && is_string($part['text'] ?? null)) {
                    $text .= $part['text'];
                }
            }
        }
        return $text;
    }

    /**
     * Returns complete Responses output items from either a response object or
     * a proxy wrapper around one.
     *
     * @param array<string, mixed> $data
     * @return list<array<string, mixed>>
     */
    private static function responsesOutput(array $data): array
    {
        $output = $data['output'] ?? null;
        if (!is_array($output) || $output === []) {
            $output = $data['response']['output'] ?? [];
        }
        if (!is_array($output)) {
            return [];
        }
        return array_values(array_filter($output, 'is_array'));
    }

    /**
     * Keeps the completed form of each output item. Streaming Responses emits
     * an incomplete `added` item before its completed `done` item, so the item
     * id is used to replace the earlier form while retaining output order.
     *
     * @param list<array<string, mixed>> $items
     * @param array<string, array<string, mixed>> $outputItems
     */
    private static function collectResponsesItems(array $items, array &$outputItems): void
    {
        foreach ($items as $index => $item) {
            $key = (string) ($item['id'] ?? count($outputItems) . ':' . $index);
            $outputItems[$key] = $item;
        }
    }

    /**
     * @param array<string, mixed> $data
     * @return array{string, string}
     */
    private static function responsesReasoning(array $data): array
    {
        if (is_string($data['reasoning'] ?? null)) {
            return [$data['reasoning'], 'reasoning'];
        }
        $output = $data['output'] ?? [];
        if (!is_array($output)) {
            return ['', ''];
        }
        $text = '';
        foreach ($output as $item) {
            if (!is_array($item) || ($item['type'] ?? '') !== 'reasoning') {
                continue;
            }
            foreach (['summary', 'content'] as $key) {
                $parts = $item[$key] ?? [];
                if (is_string($parts)) {
                    $text .= $parts;
                } elseif (is_array($parts)) {
                    foreach ($parts as $part) {
                        if (is_array($part) && is_string($part['text'] ?? null)) {
                            $text .= $part['text'];
                        }
                    }
                }
            }
        }
        return [$text, $text === '' ? '' : 'reasoning'];
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function responsesFinishReason(array $data): string
    {
        $status = $data['status'] ?? $data['response']['status'] ?? null;
        if (is_string($data['finish_reason'] ?? null)) {
            return $data['finish_reason'];
        }
        return match ($status) {
            'incomplete' => 'length',
            'failed' => 'error',
            'cancelled' => 'cancelled',
            default => 'stop',
        };
    }

    /**
     * @param array<string, mixed> $data
     * @return list<ToolCall>
     */
    private static function responsesToolCalls(array $data): array
    {
        $output = $data['output'] ?? $data['response']['output'] ?? [];
        if (!is_array($output)) {
            return [];
        }
        $calls = [];
        foreach ($output as $index => $item) {
            if (!is_array($item) || ($item['type'] ?? '') !== 'function_call') {
                continue;
            }
            $calls[] = self::call(
                (string) ($item['call_id'] ?? $item['id'] ?? "tool_{$index}"),
                (string) ($item['name'] ?? ''),
                $item['arguments'] ?? [],
            );
        }
        return $calls;
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, array{id: string, name: string, arguments: string}> $buffers
     */
    private static function collectResponsesTool(array $data, string $type, array &$buffers): void
    {
        if (in_array($type, ['response.output_item.added', 'response.output_item.done'], true) && is_array($data['item'] ?? null)) {
            self::collectResponsesItem($data['item'], $buffers);
        }
        if ($type === 'response.function_call_arguments.delta') {
            $key = (string) ($data['item_id'] ?? $data['call_id'] ?? $data['output_index'] ?? count($buffers));
            $buffers[$key] ??= ['id' => (string) ($data['call_id'] ?? $key), 'name' => '', 'arguments' => ''];
            if (is_string($data['delta'] ?? null)) {
                $buffers[$key]['arguments'] .= $data['delta'];
            }
        } elseif ($type === 'response.function_call_arguments.done') {
            $key = (string) ($data['item_id'] ?? $data['call_id'] ?? $data['output_index'] ?? count($buffers));
            $buffers[$key] ??= ['id' => (string) ($data['call_id'] ?? $key), 'name' => '', 'arguments' => ''];
            if (is_string($data['arguments'] ?? null)) {
                $buffers[$key]['arguments'] = $data['arguments'];
            }
        }
    }

    /**
     * @param array<string, mixed> $item
     * @param array<string, array{id: string, name: string, arguments: string}> $buffers
     */
    private static function collectResponsesItem(array $item, array &$buffers): void
    {
        if (($item['type'] ?? '') !== 'function_call') {
            return;
        }
        $key = (string) ($item['id'] ?? $item['call_id'] ?? count($buffers));
        $buffers[$key] ??= [
            'id' => (string) ($item['call_id'] ?? $item['id'] ?? "tool_{$key}"),
            'name' => '',
            'arguments' => '',
        ];
        if (is_string($item['call_id'] ?? null) && $item['call_id'] !== '') {
            $buffers[$key]['id'] = $item['call_id'];
        }
        if (is_string($item['name'] ?? null)) {
            $buffers[$key]['name'] = $item['name'];
        }
        if (is_string($item['arguments'] ?? null)) {
            $buffers[$key]['arguments'] = $item['arguments'];
        } elseif (is_array($item['arguments'] ?? null)) {
            $buffers[$key]['arguments'] = json_encode($item['arguments'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, array{id: string, name: string, arguments: string}> $buffers
     */
    private static function collectResponsesOutput(array $data, array &$buffers): void
    {
        if (!is_array($data['output'] ?? null)) {
            return;
        }
        foreach ($data['output'] as $item) {
            if (is_array($item)) {
                self::collectResponsesItem($item, $buffers);
            }
        }
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
