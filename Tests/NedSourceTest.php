<?php

namespace Omnilab\Ned\Tests;

use Omnilab\Exception\NotSupportedException;
use Omnilab\Exception\ProviderException;
use Omnilab\Exception\UnavailableException;
use Omnilab\Model\Domain;
use Omnilab\Model\Identifier;
use Omnilab\Model\Quantity;
use Omnilab\Model\Scheme;
use Omnilab\Ned\NedSource;
use Omnilab\Ned\NedSourceFactory;
use Omnilab\Source\Capability;
use Omnilab\Source\Query;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Fixtures recorded from ned.ipac.caltech.edu on 2026-10-15: ObjectLookup's
 * answers for M31, 3C 273 and a name NED does not know; the object
 * directory's rows (TAP) within an arcsecond of M31 and of 3C 273; the
 * galaxies within half a degree of NGC 4993.
 */
final class NedSourceTest extends TestCase
{
    /** @var list<array{string, string}> the calls: the path, the name looked up or the ADQL */
    private array $calls = [];

    private function source(?MockResponse $response = null): NedSource
    {
        $http = new MockHttpClient(function (string $method, string $url, array $options) use ($response): MockResponse {
            $path = (string) parse_url($url, \PHP_URL_PATH);
            if ('/srs/ObjectLookup' === $path) {
                self::assertSame('POST', $method);
                parse_str((string) $options['body'], $form);
                $what = json_decode($form['json'], true)['name']['v'];
            } else {
                self::assertSame('/tap/sync', $path);
                parse_str((string) parse_url($url, \PHP_URL_QUERY), $query);
                self::assertSame(['doQuery', 'ADQL', 'json'], [$query['REQUEST'], $query['LANG'], $query['FORMAT']]);
                $what = $query['QUERY'];
            }
            $this->calls[] = [$path, $what];
            if ($response) {
                return $response;
            }
            $fixture = match (true) {
                \in_array($what, ['M31', 'Messier 031', 'M 31'], true) => 'lookup-m31.json',
                '3C 273' === $what => 'lookup-3c273.json',
                str_contains($what, "CIRCLE('ICRS', 10.68479292, 41.269065") => 'row-m31.json',
                str_contains($what, "CIRCLE('ICRS', 187.2779154, 2.052388333") => 'row-3c273.json',
                str_contains($what, "CIRCLE('ICRS', 197.4487623, -23.3839623, 0.5)") => 'cone-ngc4993.json',
                default => 'lookup-unknown.json',
            };

            return new MockResponse((string) file_get_contents(__DIR__.'/Fixtures/'.$fixture));
        });
        $source = (new NedSourceFactory($http))->create();
        self::assertInstanceOf(NedSource::class, $source);

        return $source;
    }

    public function testAndromedaByItsMessierNumberInTwoCalls(): void
    {
        $m31 = $this->source()->entity('M31');

        self::assertSame(['/srs/ObjectLookup', '/tap/sync'], array_column($this->calls, 0));
        self::assertStringContainsString("WHERE 1 = CONTAINS(POINT('ICRS', ra, dec), CIRCLE('ICRS', 10.68479292, 41.269065, 0.0002777778)) ORDER BY separation ASC, prefname ASC", $this->calls[1][1], 'its row, by an arcsecond around it');
        self::assertSame('ned:Messier 031', $m31->id->key());
        self::assertSame('Messier 031', $m31->label);
        self::assertSame([], $m31->synonyms, 'MESSIER 031, as NED read the name, differs only by its case');
        self::assertSame(Domain::ASTRONOMY, $m31->domain);
        self::assertSame('galaxy', $m31->kind);
        self::assertSame('https://ned.ipac.caltech.edu/byname?objname=Messier%20031', $m31->url);
        self::assertEquals(new Quantity(10.68479292, 'deg', 2.222222173e-5), $m31->value('ra'));
        self::assertSame('00 42 44.3503 +41 16 08.634', $m31->value('coordinates'));
        self::assertEquals(new Quantity(-0.000991, null, 3e-6), $m31->value('redshift'));
        self::assertSame('SUN', $m31->value('redshift_quality'));
        self::assertSame('2000UZC...C......0F', $m31->value('redshift_reference'));
        self::assertEqualsWithDelta(121.174405026715, $m31->property('galactic_longitude')->number(), 1e-9);
        self::assertSame(407, $m31->value('distance_measurements'));
        self::assertSame(5792, $m31->value('references'));
        self::assertSame('ned', $m31->property('redshift')->source);
        self::assertNull($m31->property('separation'));
    }

    public function testAQuasarAndBySimbadsName(): void
    {
        $quasar = $this->source()->entity('3C 273');
        self::assertSame('quasar', $quasar->kind);
        self::assertSame('QSO', $quasar->value('object_type_code'));
        self::assertSame([], $quasar->synonyms);
        self::assertEqualsWithDelta(0.158338994, $quasar->property('redshift')->number(), 1e-12);

        self::assertSame('Messier 031', $this->source()->entity(Identifier::of(Scheme::SIMBAD, 'M 31'))?->label, 'a name SIMBAD gives it');
        self::assertSame('Messier 031', $this->source()->entity('ned:Messier 031')?->label);
    }

    public function testAnUnknownNameIsNullAndAnotherBaseRefused(): void
    {
        self::assertNull($this->source()->entity('zzqqxx'));
        self::assertCount(1, $this->calls, 'no row asked');
        foreach (['wikidata:Q2469', Identifier::of(Scheme::GAIA, '4472832130942575872'), 'CHEBI:27732'] as $id) {
            try {
                $this->source()->entity($id);
                self::fail(\sprintf('%s refused', $id));
            } catch (NotSupportedException) {
            }
        }
        self::assertCount(1, $this->calls);
    }

    public function testTheGalaxiesOfAConeOutsideTheContract(): void
    {
        $page = $this->source()->cone(197.4487623, -23.3839623, 0.5, 10, 'G');

        self::assertStringContainsString("AND prefphytype = 'G' ORDER BY separation ASC", $this->calls[0][1]);
        self::assertStringContainsString('SELECT TOP 11 ', $this->calls[0][1], 'one more, to know there are more');
        self::assertCount(10, $page);
        self::assertNull($page->next, 'no OFFSET in NED\'s ADQL');
        self::assertNull($page->total, 'more than ten');
        self::assertSame('NGC 4993', $page->first()->label);
        self::assertSame('arcsec', $page->first()->property('separation')->unit());
        self::assertEqualsWithDelta(272.8, $page->items[1]->property('separation')->number(), 0.1);
        self::assertSame('galaxy', $page->items[1]->kind);
    }

    public function testAConeOutsideTheSkyIsRefused(): void
    {
        foreach ([[360.0, 0.0, 1.0, null], [10.0, 91.0, 1.0, null], [10.0, 0.0, 0.0, null], [10.0, 0.0, 1.0, "G' OR '1"]] as [$ra, $dec, $radius, $type]) {
            try {
                $this->source()->cone($ra, $dec, $radius, 10, $type);
                self::fail('refused');
            } catch (\InvalidArgumentException) {
            }
        }
        self::assertSame([], $this->calls);
    }

    public function testErrors(): void
    {
        try {
            $this->source(new MockResponse('', ['http_code' => 503]))->entity('M31');
            self::fail('a 503');
        } catch (UnavailableException $e) {
            self::assertSame(503, $e->status);
        }
        $this->expectException(ProviderException::class);
        $this->expectExceptionMessage('Incorrect ADQL query');
        $this->source(new MockResponse('<VOTABLE><INFO name="QUERY_STATUS" value="ERROR">Incorrect ADQL query:  Encountered "OFFSET".</INFO></VOTABLE>', ['http_code' => 400]))->cone(10, 10, 0.1);
    }

    public function testTheFactory(): void
    {
        $factory = new NedSourceFactory(new MockHttpClient());
        $source = $factory->create(['mailto' => 'support@glitchr.io']);
        self::assertSame('ned', $factory->getName());
        self::assertSame([Capability::ENTITY], $source->capabilities(), 'no search: NED\'s directory is not searched by name in time');
        $this->expectException(NotSupportedException::class);
        $source->search(new Query('Andromeda'));
    }
}
