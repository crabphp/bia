<?php

declare(strict_types=1);

namespace Bia\Model;

use Crab\Contracts\HttpClient\Client;
use Crab\Engine\Async\Cancellation;
use Crab\Engine\Async\Task;
use Crab\Engine\EventLoop;

/**
 * Lists the models each provider serves, from its OpenAI-compatible
 * `GET /models`, all providers at once, for the `/model` picker.
 *
 * A provider without a key is asked only when it is on this machine, as a
 * local proxy often needs none. One that fails is reported, not fatal: the
 * models listed for it in the configuration are still offered.
 */
final readonly class ModelCatalog
{
    public function __construct(
        private Client $http,
        private float $timeout = 5.0,
    ) {}

    /**
     * @param array<string, array{base_url: string, endpoint?: string, api_key: ?string, models: list<string>}> $providers
     * @return array{models: list<string>, errors: array<string, string>} Models as provider/model, sorted.
     */
    public function list(array $providers, ?Cancellation $cancellation = null): array
    {
        $loop = EventLoop::current();
        $tasks = [];
        foreach ($providers as $name => $provider) {
            if ($provider['api_key'] === null && !self::local($provider['base_url'])) {
                continue;
            }
            $tasks[$name] = Task::spawn($loop, fn (): array => $this->fetch($provider, $cancellation));
        }
        $models = [];
        $errors = [];
        foreach ($providers as $name => $provider) {
            $found = $provider['models'];
            if (isset($tasks[$name])) {
                try {
                    $found = [...$found, ...$tasks[$name]->await()];
                } catch (\Throwable $error) {
                    $errors[$name] = $error->getMessage();
                }
            }
            foreach ($found as $model) {
                $models["{$name}/{$model}"] = true;
            }
        }
        $models = array_keys($models);
        sort($models, SORT_NATURAL | SORT_FLAG_CASE);
        return ['models' => $models, 'errors' => $errors];
    }

    /**
     * The model ids in a `GET /models` answer.
     *
     * @return list<string>
     */
    public static function parse(mixed $answer): array
    {
        $data = is_array($answer) ? ($answer['data'] ?? $answer['models'] ?? null) : null;
        if (!is_array($data)) {
            throw new \RuntimeException('the model list is not in the OpenAI format');
        }
        $ids = [];
        foreach ($data as $model) {
            $id = is_array($model) ? ($model['id'] ?? $model['name'] ?? null) : $model;
            if (is_string($id) && $id !== '') {
                $ids[] = str_starts_with($id, 'models/') ? substr($id, 7) : $id;
            }
        }
        return $ids;
    }

    /**
     * @param array{base_url: string, api_key: ?string} $provider
     * @return list<string>
     */
    private function fetch(array $provider, ?Cancellation $cancellation): array
    {
        $headers = ['Accept' => 'application/json'];
        if ($provider['api_key'] !== null) {
            $headers['Authorization'] = 'Bearer ' . $provider['api_key'];
            if (str_contains((string) parse_url($provider['base_url'], PHP_URL_HOST), 'anthropic.com')) {
                $headers['x-api-key'] = $provider['api_key'];
                $headers['anthropic-version'] = '2023-06-01';
            }
        }
        $response = $this->http->timeout($this->timeout)->request(
            'GET',
            rtrim($provider['base_url'], '/') . '/models',
            headers: $headers,
            cancellation: $cancellation,
            maxBytes: 4_194_304,
        );
        if (!$response->ok()) {
            throw new \RuntimeException('HTTP ' . $response->getStatusCode());
        }
        return self::parse($response->json());
    }

    private static function local(string $url): bool
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        return in_array($host, ['localhost', '127.0.0.1', '::1', '[::1]'], true);
    }
}
