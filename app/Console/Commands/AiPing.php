<?php

namespace App\Console\Commands;

use App\Services\OllamaClient;
use Illuminate\Console\Command;

class AiPing extends Command
{
    protected $signature = 'ai:ping {--ask= : Optional question to send to the model}';

    protected $description = 'Check the connection to the local Ollama server and the configured model';

    public function handle(OllamaClient $ollama): int
    {
        $this->line('Base URL : ' . config('ollama.base_url'));
        $this->line('Model    : ' . config('ollama.model'));
        $this->line('Enabled  : ' . ($ollama->enabled() ? 'yes' : 'no'));
        $this->newLine();

        if (!$ollama->enabled()) {
            $this->warn('OLLAMA_ENABLED is false. Set OLLAMA_ENABLED=true in .env, then run: php artisan config:clear');

            return self::FAILURE;
        }

        // 1. Is the server reachable, and which models does it have?
        $started = microtime(true);
        $models = $ollama->models();

        if ($models === null) {
            $this->error('Cannot reach Ollama (' . $ollama->failure() . ').');
            $this->line($this->hint($ollama->failure()));

            return self::FAILURE;
        }

        $this->info(sprintf(
            'Server reachable in %d ms. Models installed: %s',
            (int) round((microtime(true) - $started) * 1000),
            $models ? implode(', ', $models) : 'none'
        ));

        // 2. Is the configured model one of them?
        $model = (string) config('ollama.model');

        if (!in_array($model, $models, true)) {
            $this->error("Model \"{$model}\" is not installed.");
            $this->line("Run: ollama pull {$model}   (or set OLLAMA_MODEL to one of the models above, with its exact name)");

            return self::FAILURE;
        }

        // 3. A real request.
        $this->line('Sending a test question. The first call can take a while because the model has to load.');

        $started = microtime(true);

        $reply = $ollama->chat([
            ['role' => 'system', 'content' => 'You are a connectivity test. Answer with one short sentence.'],
            ['role' => 'user', 'content' => $this->option('ask') ?: 'Reply with the word OK.'],
        ], null, 60, 0);

        if ($reply === null) {
            $this->error('The model did not answer (' . $ollama->failure() . ').');
            $this->line($this->hint($ollama->failure()));

            return self::FAILURE;
        }

        $this->info(sprintf('Model answered in %.1f s:', microtime(true) - $started));
        $this->line('  ' . trim($reply));

        // 4. The assistant relies on structured (JSON) answers.
        $json = $ollama->chat([
            ['role' => 'system', 'content' => 'Answer with JSON only.'],
            ['role' => 'user', 'content' => 'Is water wet? Put true or false in the field "ok".'],
        ], [
            'type' => 'object',
            'properties' => ['ok' => ['type' => 'boolean']],
            'required' => ['ok'],
            'additionalProperties' => false,
        ], 30, 0);

        $decoded = is_string($json) ? json_decode($json, true) : null;

        if (is_array($decoded) && array_key_exists('ok', $decoded)) {
            $this->info('Structured (JSON) output: supported.');
        } else {
            $this->warn('Structured (JSON) output: NOT supported. Update Ollama to a recent version, because the assistant needs it.');
        }

        $this->newLine();
        $this->info('Ollama is ready.');

        return self::SUCCESS;
    }

    private function hint(?string $failure): string
    {
        return match ($failure) {
            'connection' => 'Is Ollama running? Check with: ollama ps. If localhost fails, try http://127.0.0.1:11434/v1 in OLLAMA_BASE_URL.',
            'timeout' => 'The model is slow or still loading. Raise OLLAMA_TIMEOUT in .env, then run: php artisan config:clear',
            'model' => 'Ollama does not have this model. Run: ollama pull <model>',
            'http' => 'Ollama returned an error. Make sure OLLAMA_BASE_URL ends with /v1, and see storage/logs/laravel.log.',
            'empty' => 'The model returned an empty answer. Try again, or use another model.',
            default => 'See storage/logs/laravel.log for details.',
        };
    }
}