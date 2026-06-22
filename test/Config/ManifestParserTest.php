<?php

declare(strict_types=1);

namespace XPHP\Config;

use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ManifestParserTest extends TestCase
{
    private ManifestParser $parser;

    protected function setUp(): void
    {
        $this->parser = new ManifestParser();
    }

    public function testParsesAFullManifest(): void
    {
        $m = $this->parser->parse('{"sources":["src","tests"],"include":["vendor/*/*"],"target":"dist","cache":".xphp-cache"}');

        self::assertSame(['src', 'tests'], $m->sources);
        self::assertSame(['vendor/*/*'], $m->include);
        self::assertSame('dist', $m->target);
        self::assertSame('.xphp-cache', $m->cache);
    }

    public function testEmptyObjectAppliesDefaults(): void
    {
        $m = $this->parser->parse('{}');

        self::assertSame(['.'], $m->sources, 'sources defaults to the manifest dir');
        self::assertSame([], $m->include);
        self::assertNull($m->target);
        self::assertNull($m->cache);
    }

    public function testOmittedSourcesAndIncludeUseDefaultsWhileOthersSet(): void
    {
        $m = $this->parser->parse('{"target":"out"}');

        self::assertSame(['.'], $m->sources);
        self::assertSame([], $m->include);
        self::assertSame('out', $m->target);
        self::assertNull($m->cache);
    }

    public function testUnknownKeysAreIgnored(): void
    {
        $m = $this->parser->parse('{"sources":["src"],"future":{"x":1},"extra":[1,2]}');

        self::assertSame(['src'], $m->sources);
        self::assertSame([], $m->include);
    }

    public function testMalformedJsonThrows(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Invalid xphp.json');
        $this->parser->parse('{"sources": [');
    }

    public function testTopLevelArrayIsRejected(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('top level must be a JSON object');
        $this->parser->parse('["src"]');
    }

    public function testNonArraySourcesIsRejected(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('"sources" must be an array of strings');
        $this->parser->parse('{"sources":"src"}');
    }

    public function testAssociativeSourcesIsRejected(): void
    {
        // A JSON object (non-list) for a list field is rejected.
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('"include" must be an array of strings');
        $this->parser->parse('{"include":{"key":"a"}}');
    }

    public function testNonStringEntryInListIsRejected(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('"sources" must contain only strings');
        $this->parser->parse('{"sources":["ok",3]}');
    }

    public function testNonStringTargetIsRejected(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('"target" must be a string');
        $this->parser->parse('{"target":123}');
    }

    public function testCustomLabelAppearsInErrors(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Invalid /pkg/xphp.json');
        $this->parser->parse('{nope', '/pkg/xphp.json');
    }
}
