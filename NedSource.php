<?php

namespace Omnilab\Ned;

use Omnilab\Exception\NotSupportedException;
use Omnilab\Exception\ProviderException;
use Omnilab\Exception\UnavailableException;
use Omnilab\Model\Domain;
use Omnilab\Model\Entity;
use Omnilab\Model\Identifier;
use Omnilab\Model\Identifiers;
use Omnilab\Model\Property;
use Omnilab\Model\Quantity;
use Omnilab\Model\Scheme;
use Omnilab\Source\Capability;
use Omnilab\Source\HttpSource;
use Omnilab\Source\Page;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * NED, the NASA/IPAC Extragalactic Database (Caltech/IPAC): an object
 * beyond our galaxy - a galaxy, a quasar, a cluster, a radio source... - by
 * any of its names, through NED's ObjectLookup service (its preferred name,
 * type, J2000 position with its uncertainty, redshift with its uncertainty
 * and quality), then its row of NED's object directory through its TAP
 * service (galactic coordinates, and how many references, photometric
 * points, redshifts, diameters, redshift-independent distances, images
 * and spectra NED holds for it); cone() gives the objects around a point of
 * the sky (outside the contract).
 *
 * No key. Two calls an entity: one to resolve the name, one to read its
 * row (a cone of one arcsecond at its position: the directory's name
 * column is not indexed, a query by name runs past a minute).
 */
final class NedSource extends HttpSource
{
    public const BASE_URI = 'https://ned.ipac.caltech.edu/';

    /** The most objects a cone gives (NED's ADQL has no OFFSET: one page). */
    public const MAX_LIMIT = 1000;

    /** NED's object types, the common ones: code => word. */
    public const TYPES = [
        'G' => 'galaxy', 'GPair' => 'pair of galaxies', 'GTrpl' => 'triple galaxy', 'GGroup' => 'group of galaxies', 'GClstr' => 'cluster of galaxies',
        'QSO' => 'quasar', 'Q_Lens' => 'lensed quasar', 'G_Lens' => 'lensed galaxy', 'QGroup' => 'group of quasars',
        'RadioS' => 'radio source', 'SmmS' => 'submillimetre source', 'IrS' => 'infrared source', 'VisS' => 'visual source', 'UvES' => 'ultraviolet excess source', 'UvS' => 'ultraviolet source',
        'XrayS' => 'X-ray source', 'GammaS' => 'gamma-ray source', 'SN' => 'supernova', 'HII' => 'HII region', 'PN' => 'planetary nebula', 'SNR' => 'supernova remnant',
        '*Cl' => 'star cluster', 'MCld' => 'molecular cloud', 'Neb' => 'nebula', '*' => 'star', '**' => 'double star', 'WD*' => 'white dwarf', 'C*' => 'carbon star',
        'AbLS' => 'absorption line system', 'EmLS' => 'emission line source', 'EmObj' => 'emission object', 'PofG' => 'part of a galaxy', 'Other' => 'other',
    ];

    /** The directory's columns read: column => [key, label]. */
    private const COUNTS = [
        'n_crosref' => ['references', 'References in NED'],
        'n_notes' => ['notes', 'Notes in NED'],
        'n_gphot' => ['photometry_points', 'Photometric measurements in NED'],
        'n_zdf' => ['redshift_measurements', 'Redshift measurements in NED'],
        'n_ddf' => ['diameter_measurements', 'Diameter measurements in NED'],
        'n_dist' => ['distance_measurements', 'Redshift-independent distances in NED (NED-D)'],
        'n_class' => ['classifications', 'Classifications in NED'],
        'n_images' => ['images', 'Images in NED'],
        'n_spectra' => ['spectra', 'Spectra in NED'],
    ];

    private const COLUMNS = ['prefname', 'ra', 'dec', 'uncmaja', 'uncmina', 'gallon', 'gallat', 'prefphytype', 'z', 'zunc', 'zflag', 'n_crosref', 'n_notes', 'n_gphot', 'n_zdf', 'n_ddf', 'n_dist', 'n_class', 'n_images', 'n_spectra'];

    public function __construct(
        HttpClientInterface $http,
        string $baseUri = self::BASE_URI,
        array $headers = [],
        float $throttle = 0.0,
    ) {
        parent::__construct($http, $baseUri, $headers, $throttle);
    }

    public function getName(): string
    {
        return 'ned';
    }

    public function capabilities(): array
    {
        return [Capability::ENTITY];
    }

    /**
     * An object by any name NED resolves ("M31", "Messier 031", "3C 273",
     * "NGC 4993"), an Identifier of the ned scheme, or of the simbad
     * scheme (a name SIMBAD gives it, which NED resolves when it knows
     * it); null when NED resolves none. Another base's identifier is
     * refused before any call.
     */
    public function entity(Identifier|string $id): ?Entity
    {
        $name = self::name($id) ?? throw NotSupportedException::identifier('ned', (string) $id);
        $data = $this->postForm('srs/ObjectLookup', ['json' => json_encode(['name' => ['v' => $name]], \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE)]);
        // ResultCode 3: one object found, its preferred name and data given; 0: nothing.
        $preferred = 3 === (int) ($data['ResultCode'] ?? 0) ? ($data['Preferred'] ?? null) : null;
        if (!\is_array($preferred) || null === self::text($preferred['Name'] ?? null)) {
            return null;
        }
        $row = null;
        $ra = $preferred['Position']['RA'] ?? null;
        $dec = $preferred['Position']['Dec'] ?? null;
        if (is_numeric($ra) && is_numeric($dec)) {
            foreach ($this->rows((float) $ra, (float) $dec, 1 / 3600, 20) as $candidate) {
                if (0 === strcasecmp(self::squash((string) $candidate['prefname']), self::squash((string) $preferred['Name']))) {
                    $row = $candidate;
                    break;
                }
            }
        }

        return $this->toEntity($preferred, $row, self::text($data['Interpreted']['Name'] ?? null));
    }

    /**
     * The objects of NED's directory within a radius of a point of the
     * sky, the nearest first (outside the contract: the core's places are
     * on the Earth), one page only - NED's ADQL has no OFFSET; Page::$next
     * is always null, and Page::$total is known when fewer than the limit
     * were found.
     *
     * @param float       $ra     right ascension, degrees (J2000)
     * @param float       $dec    declination, degrees (J2000)
     * @param float       $radius degrees
     * @param string|null $type   only NED's type of that code ("G", "QSO", "GClstr")
     *
     * @return Page<Entity>
     */
    public function cone(float $ra, float $dec, float $radius, int $limit = 50, ?string $type = null): Page
    {
        if ($ra < 0 || $ra >= 360 || $dec < -90 || $dec > 90) {
            throw new \InvalidArgumentException(\sprintf('%F, %F is not a right ascension and a declination in degrees.', $ra, $dec));
        }
        if ($radius <= 0 || $radius > 10) {
            throw new \InvalidArgumentException(\sprintf('A radius of %F degrees (NED answers up to 10).', $radius));
        }
        if (null !== $type && !preg_match('~^[A-Za-z*_]{1,6}$~', $type)) {
            throw new \InvalidArgumentException(\sprintf('"%s" is not an object type of NED.', $type));
        }
        $limit = max(1, min(self::MAX_LIMIT, $limit));
        $rows = $this->rows($ra, $dec, $radius, $limit + 1, $type);
        $more = \count($rows) > $limit;
        $items = array_map(fn (array $row) => $this->toEntity(null, $row, null), \array_slice($rows, 0, $limit));

        return new Page($items, null, $more ? null : \count($items));
    }

    /** The name to resolve for an identifier; null for another base's. */
    private static function name(Identifier|string $id): ?string
    {
        if (\is_string($id)) {
            $parsed = Identifier::parse($id);
            if (null === $parsed) {
                return Identifier::tryOf(Scheme::NED, $id)?->value;
            }
            $id = $parsed;
        }

        return \in_array($id->scheme, [Scheme::NED, Scheme::SIMBAD], true) ? $id->value : null;
    }

    /**
     * The directory's rows in a cone, the nearest first.
     *
     * @return list<array<string, mixed>>
     */
    private function rows(float $ra, float $dec, float $radius, int $top, ?string $type = null): array
    {
        $point = \sprintf("POINT('ICRS', %s, %s)", self::number($ra), self::number($dec));
        $adql = 'SELECT TOP '.$top.' '.implode(', ', self::COLUMNS).", DISTANCE(POINT('ICRS', ra, dec), $point) AS separation"
            ." FROM NEDTAP.objdir WHERE 1 = CONTAINS(POINT('ICRS', ra, dec), CIRCLE('ICRS', ".self::number($ra).', '.self::number($dec).', '.self::number($radius).'))'
            .(null === $type ? '' : " AND prefphytype = '".$type."'")
            .' ORDER BY separation ASC, prefname ASC';
        $data = $this->getJson('tap/sync', ['REQUEST' => 'doQuery', 'LANG' => 'ADQL', 'FORMAT' => 'json', 'QUERY' => $adql]) ?? [];
        $columns = array_map(static fn (array $c) => (string) $c['name'], $data['metadata'] ?? []);
        $rows = [];
        foreach ($data['data'] ?? [] as $values) {
            if (\is_array($values) && \count($values) === \count($columns)) {
                $rows[] = array_combine($columns, $values);
            }
        }

        return $rows;
    }

    /**
     * POSTs a form and reads JSON (NED's ObjectLookup takes its query as a
     * "json" form field).
     *
     * @param array<string, string> $form
     *
     * @return array<mixed>
     */
    private function postForm(string $path, array $form): array
    {
        try {
            $response = $this->http->request('POST', rtrim($this->baseUri, '/').'/'.$path, ['headers' => ['Accept' => 'application/json'] + $this->headers, 'body' => $form]);
            $status = $response->getStatusCode();
            if ($status >= 500) {
                throw new UnavailableException('ned', \sprintf('the service answered %d', $status), $status);
            }
            if (429 === $status) {
                throw new \Omnilab\Exception\RateLimitedException('ned', null);
            }
            if ($status >= 400) {
                throw new ProviderException('ned', \sprintf('the service answered %d: %s', $status, (string) self::text(substr($response->getContent(false), 0, 300))), $status);
            }
            $data = json_decode($response->getContent(false), true);
        } catch (\Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface $e) {
            throw new UnavailableException('ned', 'unreachable: '.$e->getMessage(), null, $e);
        }

        return \is_array($data) ? $data : throw new ProviderException('ned', 'the answer is not JSON');
    }

    /**
     * @param array<string, mixed>|null $preferred ObjectLookup's "Preferred"
     * @param array<string, mixed>|null $row       the directory's row
     */
    private function toEntity(?array $preferred, ?array $row, ?string $interpreted): Entity
    {
        $name = (string) (self::text($preferred['Name'] ?? null) ?? self::text($row['prefname'] ?? null));
        $properties = [];
        $add = static function (string $key, string $label, mixed $value) use (&$properties): void {
            if (null !== $value && '' !== $value) {
                $properties[] = new Property($key, $label, $value, 'ned');
            }
        };
        $position = $preferred['Position'] ?? [];
        $ra = $position['RA'] ?? $row['ra'] ?? null;
        $dec = $position['Dec'] ?? $row['dec'] ?? null;
        // ObjectLookup's uncertainty is in degrees, the directory's in arcseconds.
        $uncertainty = is_numeric($position['UncSemiMajor'] ?? null) ? (float) $position['UncSemiMajor'] : (is_numeric($row['uncmaja'] ?? null) ? $row['uncmaja'] / 3600 : null);
        $add('ra', 'Right ascension (J2000)', is_numeric($ra) ? new Quantity((float) $ra, 'deg', $uncertainty) : null);
        $add('dec', 'Declination (J2000)', is_numeric($dec) ? new Quantity((float) $dec, 'deg', $uncertainty) : null);
        $add('coordinates', 'Coordinates (J2000, sexagesimal)', is_numeric($ra) && is_numeric($dec) ? self::sexagesimal((float) $ra, (float) $dec) : null);
        $add('position_reference', 'Position: reference (bibcode)', self::text($position['RefCode'] ?? null));
        $add('galactic_longitude', 'Galactic longitude', is_numeric($row['gallon'] ?? null) ? new Quantity((float) $row['gallon'], 'deg') : null);
        $add('galactic_latitude', 'Galactic latitude', is_numeric($row['gallat'] ?? null) ? new Quantity((float) $row['gallat'], 'deg') : null);
        $code = self::text($preferred['ObjType']['Value'] ?? $row['prefphytype'] ?? null);
        $add('object_type', 'Object type (NED)', null === $code ? null : (self::TYPES[$code] ?? $code));
        $add('object_type_code', 'Object type (NED\'s code)', $code);
        $redshift = $preferred['Redshift'] ?? [];
        $z = $redshift['Value'] ?? $row['z'] ?? null;
        $zunc = $redshift['Uncertainty'] ?? $row['zunc'] ?? null;
        $add('redshift', 'Redshift (z)', is_numeric($z) ? (is_numeric($zunc) ? new Quantity((float) $z, null, (float) $zunc) : (float) $z) : null);
        $add('redshift_quality', 'Redshift: quality flag', self::text($redshift['QualityFlag'] ?? $row['zflag'] ?? null));
        $add('redshift_reference', 'Redshift: reference (bibcode)', self::text($redshift['RefCode'] ?? null));
        foreach (self::COUNTS as $column => [$key, $label]) {
            $add($key, $label, is_numeric($row[$column] ?? null) ? (int) $row[$column] : null);
        }
        $add('separation', 'Separation from the cone\'s centre', null === $preferred && is_numeric($row['separation'] ?? null) ? new Quantity($row['separation'] * 3600, 'arcsec') : null);

        $id = Identifier::of(Scheme::NED, $name);

        return new Entity(
            id: $id,
            label: $name,
            domain: Domain::ASTRONOMY,
            kind: null === $code ? null : (self::TYPES[$code] ?? $code),
            synonyms: null !== $interpreted && 0 !== strcasecmp(self::squash($interpreted), self::squash($name)) ? [$interpreted] : [],
            properties: $properties,
            url: $id->url(),
            source: 'ned',
        );
    }

    /** A name compared without its spaces' count. */
    private static function squash(string $name): string
    {
        return trim((string) preg_replace('~\s+~', ' ', $name));
    }

    /** "00 42 44.3503 +41 16 08.634". */
    private static function sexagesimal(float $ra, float $dec): string
    {
        $hours = $ra / 15;
        $h = (int) floor($hours);
        $m = (int) floor(($hours - $h) * 60);
        $s = (($hours - $h) * 60 - $m) * 60;
        if (round($s, 4) >= 60) {
            [$s, $m] = [0.0, $m + 1];
        }
        if ($m >= 60) {
            [$m, $h] = [0, ($h + 1) % 24];
        }
        $sign = $dec < 0 ? '-' : '+';
        $abs = abs($dec);
        $d = (int) floor($abs);
        $am = (int) floor(($abs - $d) * 60);
        $as = (($abs - $d) * 60 - $am) * 60;
        if (round($as, 3) >= 60) {
            [$as, $am] = [0.0, $am + 1];
        }
        if ($am >= 60) {
            [$am, $d] = [0, $d + 1];
        }

        return \sprintf('%02d %02d %07.4f %s%02d %02d %06.3f', $h, $m, $s, $sign, $d, $am, $as);
    }

    /** A number as ADQL takes it, never in exponent form. */
    private static function number(float $value): string
    {
        return rtrim(rtrim(\sprintf('%.10F', $value), '0'), '.');
    }
}
