<?php

declare(strict_types=1);

namespace XPHP\StaticAnalysis;

use PHPUnit\Framework\TestCase;

final class PhpStanOutputParserTest extends TestCase
{
    public function testParsesFileMessageWithIdentifier(): void
    {
        $json = json_encode([
            'totals' => ['errors' => 0, 'file_errors' => 1],
            'files' => [
                '/tmp/gen/Box.php' => [
                    'errors' => 1,
                    'messages' => [
                        ['message' => 'should return int but returns string', 'line' => 15, 'identifier' => 'return.type'],
                    ],
                ],
            ],
            'errors' => [],
        ], JSON_THROW_ON_ERROR);

        $result = PhpStanOutputParser::parse($json, '');

        self::assertTrue($result->ranOk);
        self::assertCount(1, $result->findings);
        $finding = $result->findings[0];
        self::assertSame('/tmp/gen/Box.php', $finding->file);
        self::assertSame(15, $finding->line);
        self::assertSame('should return int but returns string', $finding->message);
        self::assertSame('return.type', $finding->identifier);
    }

    public function testIdentifierIsNullWhenAbsentAndLineDefaultsToZero(): void
    {
        $json = json_encode([
            'files' => [
                '/tmp/gen/Box.php' => [
                    'messages' => [
                        ['message' => 'file-level problem'], // no line, no identifier
                    ],
                ],
            ],
        ], JSON_THROW_ON_ERROR);

        $result = PhpStanOutputParser::parse($json, '');

        self::assertTrue($result->ranOk);
        self::assertCount(1, $result->findings);
        self::assertSame(0, $result->findings[0]->line);
        self::assertNull($result->findings[0]->identifier);
    }

    public function testEmptyFilesMapIsACleanRun(): void
    {
        $result = PhpStanOutputParser::parse('{"files":{},"errors":[]}', '');

        self::assertTrue($result->ranOk);
        self::assertSame([], $result->findings);
    }

    public function testNonJsonStdoutIsAFailedRunWithStderrMessage(): void
    {
        $result = PhpStanOutputParser::parse('PHP Fatal error: boom', 'Configuration file not found');

        self::assertFalse($result->ranOk);
        self::assertSame([], $result->findings);
        self::assertSame('Configuration file not found', $result->errorOutput);
    }

    public function testFailureMessageFallsBackToStdoutWhenStderrEmpty(): void
    {
        $result = PhpStanOutputParser::parse('not json at all', '   ');

        self::assertFalse($result->ranOk);
        self::assertSame('not json at all', $result->errorOutput);
    }

    public function testFailureMessageWhenBothStreamsEmpty(): void
    {
        $result = PhpStanOutputParser::parse('', '');

        self::assertFalse($result->ranOk);
        self::assertSame('PHPStan produced no analysable output', $result->errorOutput);
    }

    public function testValidJsonWithoutFilesKeyIsAFailedRun(): void
    {
        $result = PhpStanOutputParser::parse('{"totals":{"errors":0}}', 'some stderr');

        self::assertFalse($result->ranOk);
        self::assertSame('some stderr', $result->errorOutput);
    }

    public function testFilesKeySetButNotAnArrayIsAFailedRun(): void
    {
        // 'files' present but not a map (distinguishes the middle clause of the
        // is_array/isset/is_array guard from the others).
        $result = PhpStanOutputParser::parse('{"files":"oops"}', 'stderr text');

        self::assertFalse($result->ranOk);
        self::assertSame('stderr text', $result->errorOutput);
    }

    public function testFailureMessageTrimsStdoutWhitespace(): void
    {
        $result = PhpStanOutputParser::parse('  padded  ', '');

        self::assertFalse($result->ranOk);
        self::assertSame('padded', $result->errorOutput);
    }

    public function testTopLevelErrorsCoerceNonStringsToEmpty(): void
    {
        $json = json_encode([
            'files' => [],
            'errors' => ['real error', 123], // the int must be coerced to '' by the map
        ], JSON_THROW_ON_ERROR);

        $result = PhpStanOutputParser::parse($json, '');

        self::assertFalse($result->ranOk);
        self::assertSame("real error\n", $result->errorOutput);
    }

    public function testWhitespaceOnlyTopLevelErrorFallsBackToGenericMessage(): void
    {
        $json = json_encode(['files' => [], 'errors' => ['   ']], JSON_THROW_ON_ERROR);

        $result = PhpStanOutputParser::parse($json, '');

        self::assertFalse($result->ranOk);
        self::assertSame('PHPStan reported a general error', $result->errorOutput);
    }

    public function testScalarJsonIsAFailedRun(): void
    {
        // json_decode('123') is a valid int, not an array — must be a failed run.
        $result = PhpStanOutputParser::parse('123', 'stderr here');

        self::assertFalse($result->ranOk);
        self::assertSame('stderr here', $result->errorOutput);
    }

    public function testTopLevelErrorsWithNoFileFindingsIsAFailedRun(): void
    {
        $json = json_encode([
            'files' => [],
            'errors' => ['Ignored error pattern was not matched', 'Another general error'],
        ], JSON_THROW_ON_ERROR);

        $result = PhpStanOutputParser::parse($json, '');

        self::assertFalse($result->ranOk);
        self::assertSame("Ignored error pattern was not matched\nAnother general error", $result->errorOutput);
    }

    public function testFileFindingsWinOverTopLevelErrors(): void
    {
        // When there ARE file findings, top-level errors don't turn it into a failure.
        $json = json_encode([
            'files' => [
                '/tmp/gen/Box.php' => ['messages' => [['message' => 'real bug', 'line' => 3]]],
            ],
            'errors' => ['some general note'],
        ], JSON_THROW_ON_ERROR);

        $result = PhpStanOutputParser::parse($json, '');

        self::assertTrue($result->ranOk);
        self::assertCount(1, $result->findings);
    }

    public function testMalformedEntriesAreSkippedNotFatal(): void
    {
        $json = json_encode([
            'files' => [
                '/tmp/ok.php' => ['messages' => [['message' => 'kept', 'line' => 1]]],
                '/tmp/no-messages.php' => ['errors' => 2], // missing 'messages'
                '/tmp/bad-messages.php' => ['messages' => 'not-an-array'],
                '/tmp/scalar-data.php' => 'not-an-array', // data not an array → skipped
                // A numeric file key decodes to an int key, exercising the is_string($file) guard.
                '7' => ['messages' => [['message' => 'numeric-key', 'line' => 1]]],
                '/tmp/bad-entry.php' => [
                    'messages' => [
                        'not-an-array',                 // skipped
                        ['line' => 9],                  // no 'message' → skipped
                        ['message' => 42],              // non-string message → skipped
                        ['message' => 'also kept', 'line' => 2],
                    ],
                ],
            ],
        ], JSON_THROW_ON_ERROR);

        $result = PhpStanOutputParser::parse($json, '');

        self::assertTrue($result->ranOk);
        $messages = array_map(static fn (PhpStanFinding $f): string => $f->message, $result->findings);
        self::assertSame(['kept', 'also kept'], $messages);
    }
}
