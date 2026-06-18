<?php

declare(strict_types=1);

namespace XPHP\StaticAnalysis;

/**
 * Turns PHPStan's `--error-format=json` stdout (and stderr) into a
 * {@see PhpStanResult}. Kept separate from {@see PhpStanRunner} so the parsing —
 * including all the malformed-output guards — is unit-testable without shelling
 * out to a real PHPStan.
 *
 * A run is "ok" only when stdout is a JSON object carrying a `files` map. No
 * parseable JSON (a config/fatal error printed to stderr), or valid JSON whose
 * only content is top-level file-less errors, is a FAILED run — the caller turns
 * that into a Warning rather than a false clean pass.
 */
final class PhpStanOutputParser
{
    public static function parse(string $stdout, string $stderr): PhpStanResult
    {
        $decoded = json_decode($stdout, true);
        // @infection-ignore-all ReturnRemoval -- equivalent: the next guard also
        // rejects a non-array $decoded (isset() on a scalar offset is false and
        // suppresses the warning), so removing this early return changes nothing.
        // Kept for readability: "the payload must be a JSON object".
        if (!is_array($decoded)) {
            return PhpStanResult::failed(self::failureMessage($stdout, $stderr));
        }
        if (!isset($decoded['files']) || !is_array($decoded['files'])) {
            return PhpStanResult::failed(self::failureMessage($stdout, $stderr));
        }

        $findings = [];
        foreach ($decoded['files'] as $file => $data) {
            if (!is_string($file) || !is_array($data) || !isset($data['messages']) || !is_array($data['messages'])) {
                continue;
            }
            foreach ($data['messages'] as $message) {
                if (!is_array($message) || !isset($message['message']) || !is_string($message['message'])) {
                    continue;
                }
                // Generated-class findings map to the template DECLARATION line, not the
                // finding's own line, so a missing/odd line is harmless — default to 0.
                $line = isset($message['line']) && is_int($message['line']) ? $message['line'] : 0;
                $identifier = isset($message['identifier']) && is_string($message['identifier'])
                    ? $message['identifier']
                    : null;
                $findings[] = new PhpStanFinding($file, $line, $message['message'], $identifier);
            }
        }

        // Valid JSON but only top-level (file-less) errors means PHPStan itself had a
        // problem (e.g. an unmatched ignore pattern) — not a clean pass.
        $topLevel = $decoded['errors'] ?? [];
        if ($findings === [] && is_array($topLevel) && $topLevel !== []) {
            $joined = implode("\n", array_map(static fn (mixed $e): string => is_string($e) ? $e : '', $topLevel));

            return PhpStanResult::failed(trim($joined) !== '' ? $joined : 'PHPStan reported a general error');
        }

        return PhpStanResult::ok($findings);
    }

    private static function failureMessage(string $stdout, string $stderr): string
    {
        $stderr = trim($stderr);
        if ($stderr !== '') {
            return $stderr;
        }
        $stdout = trim($stdout);
        if ($stdout !== '') {
            return $stdout;
        }

        return 'PHPStan produced no analysable output';
    }
}
