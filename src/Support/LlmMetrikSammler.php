<?php

declare(strict_types=1);

namespace Hwkdo\IntranetAppBewerbungen\Support;

use Illuminate\Http\Client\Events\ResponseReceived;
use Illuminate\Support\Facades\Event;
use Laravel\Ai\Events\AgentPrompted;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;

/**
 * Fängt Usage, Meta und llama.cpp-timings für genau einen Agent-Aufruf.
 * Die Listener bleiben pro Prozess registriert und wirken nur, solange ein Sammler offen ist.
 */
final class LlmMetrikSammler
{
    /** @var list<self> */
    private static array $offen = [];

    private static bool $hooks = false;

    public ?string $provider = null;

    public ?string $modell = null;

    public ?int $inputTokens = null;

    public ?int $outputTokens = null;

    /** @var array<string, mixed>|null */
    public ?array $timings = null;

    public function __construct(public readonly object $agent)
    {
        self::hooks();
        self::$offen[] = $this;
    }

    /**
     * @return array{provider: string|null, modell: string|null, input_tokens: int|null, output_tokens: int|null, tokens_pro_sekunde: float|null, prompt_tokens_pro_sekunde: float|null, prompt_ms: int|null}
     */
    public function schliessen(): array
    {
        self::$offen = array_values(array_filter(
            self::$offen,
            fn (self $sammler): bool => $sammler !== $this,
        ));

        $predicted = $this->timings['predicted_per_second'] ?? null;
        $promptRate = $this->timings['prompt_per_second'] ?? null;
        $promptMs = $this->timings['prompt_ms'] ?? null;

        return [
            'provider' => $this->provider,
            'modell' => $this->modell,
            'input_tokens' => $this->inputTokens,
            'output_tokens' => $this->outputTokens,
            'tokens_pro_sekunde' => is_numeric($predicted) ? round((float) $predicted, 1) : null,
            'prompt_tokens_pro_sekunde' => is_numeric($promptRate) ? round((float) $promptRate, 1) : null,
            'prompt_ms' => is_numeric($promptMs) ? (int) round((float) $promptMs) : null,
        ];
    }

    private static function hooks(): void
    {
        if (self::$hooks) {
            return;
        }

        self::$hooks = true;

        Event::listen(AgentPrompted::class, function (AgentPrompted $event): void {
            $sammler = self::fuer($event->prompt->agent);
            if (! $sammler instanceof self) {
                return;
            }

            $usage = $event->response->usage ?? null;
            $meta = $event->response->meta ?? null;
            if ($usage instanceof Usage) {
                $sammler->inputTokens = $usage->inputTokens;
                $sammler->outputTokens = $usage->outputTokens;
            }
            if ($meta instanceof Meta) {
                $sammler->provider = $meta->provider;
                $sammler->modell = $meta->model;
            }
        });

        Event::listen(ResponseReceived::class, function (ResponseReceived $event): void {
            $sammler = self::$offen === [] ? null : self::$offen[array_key_last(self::$offen)];
            if (! $sammler instanceof self || ! self::istLlamaAntwort($event)) {
                return;
            }

            $json = $event->response->json();
            if (! is_array($json) || ! is_array($json['timings'] ?? null)) {
                return;
            }

            $sammler->timings = $json['timings'];
        });
    }

    private static function istLlamaAntwort(ResponseReceived $event): bool
    {
        $hosts = array_values(array_filter(
            [
                parse_url((string) config('intranet-app-bewerbungen.pipeline.llama_base_url', ''), PHP_URL_HOST),
                parse_url((string) config('ai.providers.gemma-llama-cpp.url', ''), PHP_URL_HOST),
            ],
            fn (mixed $host): bool => is_string($host) && $host !== '',
        ));

        if ($hosts === []) {
            return true;
        }

        $urlHost = parse_url($event->request->url(), PHP_URL_HOST);

        return is_string($urlHost) && in_array($urlHost, $hosts, true);
    }

    private static function fuer(object $agent): ?self
    {
        foreach (array_reverse(self::$offen) as $sammler) {
            if ($sammler->agent === $agent) {
                return $sammler;
            }
        }

        return null;
    }
}
