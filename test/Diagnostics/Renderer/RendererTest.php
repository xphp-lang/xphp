<?php

declare(strict_types=1);

namespace XPHP\Diagnostics\Renderer;

use PHPUnit\Framework\TestCase;
use XPHP\Diagnostics\Diagnostic;
use XPHP\Diagnostics\DiagnosticSource;
use XPHP\Diagnostics\Severity;
use XPHP\Diagnostics\SourceLocation;

final class RendererTest extends TestCase
{
    /** @return list<Diagnostic> */
    private function sample(): array
    {
        return [
            new Diagnostic(
                Severity::Error,
                'xphp.bound_violation',
                'bad bound',
                new SourceLocation('/src/Box.xphp', 7, 3),
            ),
            new Diagnostic(
                Severity::Warning,
                'phpstan.return',
                'maybe',
                null,
                'App\\Box<int>',
                DiagnosticSource::PhpStan,
            ),
        ];
    }

    public function testTextEmpty(): void
    {
        self::assertSame('No problems found.' . PHP_EOL, (new TextRenderer())->render([]));
    }

    public function testTextRender(): void
    {
        $expected = implode(PHP_EOL, [
            'error: bad bound',
            '  at /src/Box.xphp:7:3 [xphp.bound_violation]',
            '',
            'warning: maybe',
            '  [phpstan.return]',
            '  triggered by App\\Box<int>',
        ]) . PHP_EOL;

        self::assertSame($expected, (new TextRenderer())->render($this->sample()));
    }

    public function testTextOmitsColumnWhenAbsent(): void
    {
        $d = [new Diagnostic(Severity::Error, 'c', 'm', new SourceLocation('/a.xphp', 4))];
        self::assertSame(
            'error: m' . PHP_EOL . '  at /a.xphp:4 [c]' . PHP_EOL,
            (new TextRenderer())->render($d),
        );
    }

    public function testJsonContract(): void
    {
        $out = (new JsonRenderer())->render($this->sample());
        // Pin the whole raw formatting: pretty-printed (space after key), slashes
        // unescaped, and a trailing newline.
        $expected = <<<'JSON'
            {
                "diagnostics": [
                    {
                        "severity": "error",
                        "code": "xphp.bound_violation",
                        "message": "bad bound",
                        "source": "xphp",
                        "triggeredBy": null,
                        "file": "/src/Box.xphp",
                        "line": 7,
                        "column": 3
                    },
                    {
                        "severity": "warning",
                        "code": "phpstan.return",
                        "message": "maybe",
                        "source": "phpstan",
                        "triggeredBy": "App\\Box<int>",
                        "file": null,
                        "line": null,
                        "column": null
                    }
                ]
            }
            JSON . PHP_EOL;

        self::assertSame($expected, $out);
    }

    public function testJsonEmpty(): void
    {
        self::assertSame('{' . PHP_EOL . '    "diagnostics": []' . PHP_EOL . '}' . PHP_EOL, (new JsonRenderer())->render([]));
    }

    public function testGithubRender(): void
    {
        $expected = implode(PHP_EOL, [
            '::error file=/src/Box.xphp,line=7,col=3::bad bound',
            // The second diagnostic carries triggeredBy, folded into the message.
            '::warning::maybe (triggered by App\\Box<int>)',
        ]) . PHP_EOL;

        self::assertSame($expected, (new GithubRenderer())->render($this->sample()));
    }

    public function testGithubEscapesMessageAndProperties(): void
    {
        $d = [new Diagnostic(
            Severity::Notice,
            'c',
            "line one\nline two",
            new SourceLocation('/weird,name:x.xphp', 1),
        )];

        self::assertSame(
            '::notice file=/weird%2Cname%3Ax.xphp,line=1::line one%0Aline two' . PHP_EOL,
            (new GithubRenderer())->render($d),
        );
    }

    public function testGithubEmpty(): void
    {
        self::assertSame('', (new GithubRenderer())->render([]));
    }

    public function testGithubEscapesPercentAndCarriageReturnInMessage(): void
    {
        $d = [new Diagnostic(Severity::Error, 'c', "50%\rdone")];

        // `%` must be escaped first (to %25), then `\r` to %0D — no double-escaping.
        self::assertSame('::error::50%25%0Ddone' . PHP_EOL, (new GithubRenderer())->render($d));
    }
}
