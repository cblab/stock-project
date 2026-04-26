<?php

declare(strict_types=1);

namespace App\Service\Trade;

use RuntimeException;

/**
 * Provides system version configuration for Trade Events.
 *
 * Loads version information from config/system_versions.json.
 */
final readonly class TradeVersionProvider
{
    private const CONFIG_PATH = __DIR__ . '/../../../../config/system_versions.json';

    /**
     * Returns the current system versions.
     *
     * @return array{
     *     scoring_version: string,
     *     policy_version: string,
     *     model_version: string|null,
     *     macro_version: string|null
     * }
     *
     * @throws RuntimeException If the configuration file is missing, invalid, or incomplete.
     */
    public function current(): array
    {
        if (!file_exists(self::CONFIG_PATH)) {
            throw new RuntimeException(
                sprintf('System versions configuration file not found: %s', self::CONFIG_PATH)
            );
        }

        $content = file_get_contents(self::CONFIG_PATH);
        if ($content === false) {
            throw new RuntimeException(
                sprintf('Failed to read system versions configuration file: %s', self::CONFIG_PATH)
            );
        }

        $data = json_decode($content, true);
        if ($data === null || !is_array($data)) {
            throw new RuntimeException(
                sprintf('Invalid JSON in system versions configuration file: %s', json_last_error_msg())
            );
        }

        // Required keys that must be present and non-empty
        $requiredKeys = ['scoring_version', 'policy_version'];

        foreach ($requiredKeys as $key) {
            if (!array_key_exists($key, $data)) {
                throw new RuntimeException(
                    sprintf('Missing required key in system versions configuration: %s', $key)
                );
            }

            if (!is_string($data[$key]) || $data[$key] === '') {
                throw new RuntimeException(
                    sprintf('Empty or invalid value for required key: %s', $key)
                );
            }
        }

        return [
            'scoring_version' => $data['scoring_version'],
            'policy_version' => $data['policy_version'],
            'model_version' => $data['model_version'] ?? null,
            'macro_version' => $data['macro_version'] ?? null,
        ];
    }
}
