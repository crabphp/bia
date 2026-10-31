<?php

declare(strict_types=1);

namespace Bia\Model;

use Bia\Contracts\Model;
use Bia\Contracts\ModelEvent;
use Bia\Contracts\ModelRequest;
use Bia\Contracts\ModelResult;
use Crab\Contracts\HttpClient\Client;
use Crab\Engine\Async\Cancellation;

/**
 * Streams Chat Completions-compatible responses, including tdaproxy and other
 * providers that expose an OpenAI-compatible endpoint.
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
            'messages' => $request->jsonMessages(),
            'stream' => true,
        ];
        if ($this->streamUsage && str_ends_with(rtrim($this->endpoint, '/'), 'chat/completions')) {
            // Chat Completions only reports usage on a stream when asked.
            $payload['stream_options'] = ['include_usage' => true];
        }
        if ($request->tools !== []) {
            $payload['tools'] = $request->tools;
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
            )
            ->throw();

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
            $usage = is_array($data['usage'] ?? null) ? $data['usage'] : [];
            if ($usage !== []) {
                $emit(ModelEvent::usage($usage));
            }
            $finishReason = is_string($data['choices'][0]['finish_reason'] ?? null)
                ? $data['choices'][0]['finish_reason']
                : 'stop';
            $toolCalls = self::toolCalls($data);
            foreach ($toolCalls as $toolCall) {
                $emit(ModelEvent::toolCall($toolCall));
            }
            $result = new ModelResult($text, $finishReason, $usage, $toolCalls, $data);
            $emit(ModelEvent::completed(['finish_reason' => $finishReason, 'usage' => $usage]));
            return $result;
        }

        $text = '';
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
            $raw = $data;
            $delta = self::text($data);
            if ($delta !== '') {
                $text .= $delta;
                $emit(ModelEvent::text($delta));
            }
            self::collectToolCalls($data, $toolBuffers);
            if (isset($data['usage']) && is_array($data['usage'])) {
                $usage = $data['usage'];
                $emit(ModelEvent::usage($usage));
            }
            $reason = $data['choices'][0]['finish_reason'] ?? null;
            if (is_string($reason) && $reason !== '') {
                $finishReason = $reason;
            }
        }
        $toolCalls = self::decodeToolBuffers($toolBuffers);
        foreach ($toolCalls as $toolCall) {
            $emit(ModelEvent::toolCall($toolCall));
        }
        $result = new ModelResult($text, $finishReason, $usage, $toolCalls, $raw);
        $emit(ModelEvent::completed(['finish_reason' => $finishReason, 'usage' => $usage]));
        return $result;
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
     * @param array<string, mixed> $data
     * @return list<array{id: string, name: string, arguments: array<string, mixed>}>
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
            $arguments = $function['arguments'] ?? [];
            if (!is_string($name)) {
                continue;
            }
            $result[] = self::call((string) ($call['id'] ?? "tool_{$index}"), $name, $arguments);
        }
        return $result;
    }

    /**
     * @param array<string, array{id: string, name: string, arguments: string}> $buffers
     * @return list<array{id: string, name: string, arguments: array<string, mixed>}>
     */
    private static function decodeToolBuffers(array $buffers): array
    {
        $result = [];
        foreach ($buffers as $buffer) {
            $result[] = self::call($buffer['id'], $buffer['name'], $buffer['arguments']);
        }
        return $result;
    }

    /**
     * One normalized call. Arguments that are not a JSON object, such as
     * those of a reply cut off at its length limit, are reported as an error
     * for the model to see instead of running the tool with none.
     *
     * @return array{id: string, name: string, arguments: array<string, mixed>, error?: string}
     */
    private static function call(string $id, string $name, mixed $arguments): array
    {
        if (is_string($arguments)) {
            $arguments = trim($arguments) === '' ? [] : json_decode($arguments, true);
        }
        if (is_array($arguments) && (!array_is_list($arguments) || $arguments === [])) {
            return ['id' => $id, 'name' => $name, 'arguments' => $arguments];
        }
        return [
            'id' => $id,
            'name' => $name,
            'arguments' => [],
            'error' => "The arguments for {$name} were not a valid JSON object; send the call again with complete arguments.",
        ];
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
            $buffers[$key] ??= [
                'id' => (string) ($call['id'] ?? "tool_{$key}"),
                'name' => '',
                'arguments' => '',
            ];
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
