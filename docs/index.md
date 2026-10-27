# omnilab/ned

NED, the NASA/IPAC Extragalactic Database, through its ObjectLookup and TAP services
(`https://ned.ipac.caltech.edu/`), for [omnisense/omnilab](https://github.com/glitchr-omnisense/omnilab):
galaxies, quasars, clusters and the other objects beyond our galaxy by any of their names - type,
position, redshift and its quality, the number of distances, photometry and spectra NED holds -,
as entities; outside the contract, the objects of a cone on the sky. No key.

## Installation

```sh
composer require omnilab/ned
```

```php
use Omnilab\Ned\NedSourceFactory;

$ned = (new NedSourceFactory($httpClient))->create([
    'mailto' => 'support@glitchr.io',  // optional, in the User-Agent
    'throttle' => 0,                   // optional: seconds between two calls
]);
```

With the Symfony bundle, the factory is registered as `ned` when the bundle knows it; otherwise
register `NedSourceFactory` as a service (it is autoconfigured as a `SourceFactoryInterface`).
Give the HTTP client a timeout of 60 s or more for cones.

## Credentials

None: NED's ObjectLookup and TAP services take no key and no account.

## Options

The factory's options, with the defaults it applies (`NedSourceFactory`):

| Option | Type | Default | Required | Effect |
|---|---|---|---|---|
| `mailto` | `?string` | null | no | a contact address, sent in the User-Agent |
| `user_agent` | `?string` | null | no | the whole User-Agent, in place of the one made from `mailto` |
| `throttle` | `float` | `0` | no | seconds left between two calls: NED publishes no rate (its TAP answers in 1 to 40 s) |
| `base_uri` | `string` | `https://ned.ipac.caltech.edu/` | no | NED's site (`srs/ObjectLookup` and `tap/sync` are joined to it), or a mirror |

## Capabilities

`capabilities()` answers `entity`; an operation it does not answer throws `NotSupportedException` before any call.

| Capability | Supported | |
|---|---|---|
| `entity` | yes | any name NED resolves (`M31`, `3C 273`, `Abell 1689`), `ned:`, a `simbad` identifier, its `byname` page: ObjectLookup, then the directory's row by a cone of an arcsecond; another base refused before any call |
| `search` | no | ObjectLookup resolves one name, and the directory cannot be searched by name in time |
| `series` | no |  |
| `products` | no |  |
| `occurrences` | no |  |
| `datasets` | no |  |
| `jobs` | no |  |
| `open` | no |  |
| `structure` | no |  |
| `cross_references` | no | none: NED gives no other base's identifier here |
| `forecast` | no |  |
| `historical` | no |  |
| `area` | no | the core's places are on the Earth: a cone on the sky is `cone()`'s |
| `station` | no |  |
| `cloud_cover` | no |  |

Outside the contract, on `Omnilab\Ned\NedSource`: `cone(float $ra, float $dec, float $radius, int
$limit = 50, ?string $type = null): Page` - the objects of NED's directory within a radius (degrees,
10 at most) of a point of the sky (J2000 degrees), the nearest first, 1,000 at most, one page,
optionally of one NED type code (`G`, `QSO`, `GClstr`...).

## Which API

Two of NED's services: **ObjectLookup** (`https://ned.ipac.caltech.edu/srs/ObjectLookup`, a POST
whose `json` form field holds `{"name":{"v":"M31"}}`; version 2.1), which resolves a name and
gives the object's preferred name, position, type and redshift; and **TAP**
(`https://ned.ipac.caltech.edu/tap/sync`, ADQL, `FORMAT=json`), whose one table,
`NEDTAP.objdir`, is NED's object directory: the same values, the galactic coordinates, and the
number of each kind of data NED holds for the object.

| Method | Reads | Calls |
|---|---|---|
| `entity()` | any name NED resolves (`M31`, `Messier 031`, `3C 273`, `NGC 4993`, `Abell 1689`), `ned:...`, an `Identifier` of the `ned` or `simbad` scheme, NED's `byname` page | ObjectLookup, then `SELECT TOP 20 ... FROM NEDTAP.objdir WHERE 1 = CONTAINS(POINT('ICRS', ra, dec), CIRCLE('ICRS', ra, dec, 1"))` and the row of its preferred name |
| `cone()` | outside the contract: a right ascension and declination (degrees, J2000), a radius (degrees, 10 at most), how many (1,000 at most), NED's type code (`G`, `QSO`, `GClstr`...) | `SELECT TOP n+1 ..., DISTANCE(...) AS separation FROM NEDTAP.objdir WHERE 1 = CONTAINS(...) [AND prefphytype = 'G'] ORDER BY separation ASC, prefname ASC` |

ObjectLookup's `ResultCode` 3 is an object found; anything else (0: not found) is `null`, without
asking a row. Another base's identifier (`wikidata:...`, `gaia:...`) is refused before any call.

**Why a cone for one row**: `prefname` is not indexed in `objdir` - `WHERE prefname = 'Messier
031'` ran past 60 s on 2026-10-15, a `LIKE` past 40 s -, while a cone of an arcsecond at the
object's position answered in 1 to 6 s. The row whose preferred name is the object's (case and
spaces aside) is read; without one, the entity holds ObjectLookup's values alone.

**Why no search**: ObjectLookup resolves one name and the directory cannot be searched by name in
time; `entity()` reads names. **Why the cone is outside the contract**: the core's places are on
the Earth (as omnilab/gaia's `cone()`). NED's ADQL has no `OFFSET` (a 400, "Encountered
OFFSET"), so a cone is one page: `next` is null, `total` is known when fewer than the limit were
found (one row more is asked to tell).

## What an entity holds

`id` in the `ned` scheme (its preferred name: `ned:Messier 031`), `label` the preferred name,
`domain` astronomy, `kind` the type in words (`galaxy`, `quasar`, `cluster of galaxies`, `radio
source`... NED's code itself when not listed), `synonyms` the name as NED read the one asked when
it differs from the preferred one by more than its case, `url` NED's `byname` page. No
identifiers of other bases (NED gives none here), no `related`, no place nor time.

| Key | From | Value |
|---|---|---|
| `ra`, `dec` | ObjectLookup's Position ± UncSemiMajor (degrees); a cone's row: ra, dec ± uncmaja (arcsec, given in degrees) | Quantity, deg, J2000 |
| `coordinates` | the same, sexagesimal | text `00 42 44.3503 +41 16 08.634` |
| `position_reference` | Position.RefCode | bibcode |
| `galactic_longitude`, `galactic_latitude` | gallon, gallat | Quantity, deg |
| `object_type`, `object_type_code` | ObjType.Value, prefphytype | text: `galaxy`; `G` |
| `redshift` | Redshift.Value ± Uncertainty (z, zunc) | Quantity without unit, or a number |
| `redshift_quality`, `redshift_reference` | QualityFlag (zflag), RefCode | text: `SUN`; bibcode |
| `references`, `notes`, `photometry_points`, `redshift_measurements`, `diameter_measurements`, `distance_measurements`, `classifications`, `images`, `spectra` | n_crosref, n_notes, n_gphot, n_zdf, n_ddf, n_dist, n_class, n_images, n_spectra | number |
| `separation` | a cone's only | Quantity, arcsec |

## What NED does not give here: the distances themselves

NED's redshift-independent distances (NED-D: M31's 407 measurements) and its cosmology-corrected
distances are served as web pages (`cgi-bin/nDistance`, 128 kB of HTML in 21 s for M31) and by
the old `nph-objsearch` (a VOTable of the main values, 21 s), not by ObjectLookup nor TAP: they
are not read - their number is (`distance_measurements`). Nor are the photometry, the diameters,
the classifications, the notes, the references, the images and spectra, the cross-identifications
(the other names), searches by parameters or by reference.

## The Collector: from SIMBAD into NED

omnilab/simbad gives an object its SIMBAD names (`simbad:M 87`); NED resolves them:
`collect('simbad', 'M87', 1, ['ned'])` merges SIMBAD's record with NED's (`Messier 087`, z
0.004282963, 118 distances). NED gives no other base's identifier, so nothing leads out of it.

## Example

```php
<?php

require __DIR__.'/vendor/autoload.php';

use Omnilab\Ned\NedSourceFactory;
use Symfony\Component\HttpClient\HttpClient;

$ned = (new NedSourceFactory(HttpClient::create(['timeout' => 90])))->create(['mailto' => 'you@example.org']);

$m31 = $ned->entity('M31');
echo $m31->label, ' (', $m31->kind, '): ', $m31->value('coordinates'), ', z = ', $m31->property('redshift'), ' (', $m31->value('redshift_quality'), '), ', $m31->value('distance_measurements'), " distances in NED\n";

$near = $ned->cone(197.448776, -23.383831, 0.1, 5, 'G');   // NGC 4993, within 0.1 degree
foreach ($near as $galaxy) {
    echo '  ', $galaxy->label, ' at ', $galaxy->property('separation'), "\n";
}
var_dump($ned->entity('zzqqxx no such object'));
```

```
Messier 031 (galaxy): 00 42 44.3503 +41 16 08.634, z = -0.000991 ± 0.000003 (SUN), 407 distances in NED
  NGC 4993 at 0.4748427944 arcsec
  WISEA J130929.07-232435.2 at 273.0242233892 arcsec
NULL
```

(as answered on 2026-10-27: four calls; the HTTP client's timeout raised for the cone)

```yaml
# config/packages/omnilab.yaml
omnilab:
    sources:
        ned: { factory: ned, options: { mailto: '%env(OMNILAB_MAILTO)%' } }
```

`cone()` is a method of `NedSource`, not of the interface the bundle injects: check the instance
before calling it.

## Errors

TAP's calls go through `HttpSource` (a 404, 410 or 204 is null; a 429 a `RateLimitedException`; a
5xx, a time-out or a host that does not answer an `UnavailableException`; any other 4xx a
`ProviderException` with what the service said, TAP's VOTable error read). ObjectLookup's POST is
the source's own and mapped alike: a 5xx an `UnavailableException`, a 429 a `RateLimitedException`
(its `retryAfter` not read), another 4xx a `ProviderException` with the start of its body, an
answer that is not JSON a `ProviderException`. A `ResultCode` other than 3 (found) is null.

Before any call: another base's identifier (`NotSupportedException`); in `cone()`, a position
outside the sky, a radius not above 0 or beyond 10°, a type code that is not one to six letters (`*` and `_` allowed)
(`\InvalidArgumentException`).

## Verified, and not

Against the real service on 2026-10-15: M31 (`Messier 031`, two calls, 2.8 s), 3C 273 (quasar),
NGC 4993, Abell 1689 (cluster of galaxies), `simbad:M 87` (`Messier 087`), an unknown name
(`ResultCode` 0); the galaxies within 0.5° of NGC 4993 (40 s) and within 0.1° (two, 2.6 s); a
query by `prefname` (past 60 s), by `LIKE` (past 40 s), with `OFFSET` (400). The fixtures in
`Tests/Fixtures/` are its answers.

Again on 2026-10-27: M31 (407 distances), the galaxies within 0.1° of NGC 4993, an unknown name
(the example above).

Not verified: a 429 or a 5xx from NED (none met); ObjectLookup's other result codes (an
ambiguous name was not met: NED resolved every name tried to one object); a mirror (`base_uri`).

## Responsibility

This package is an independent, unofficial client: it is not affiliated with, endorsed by or
sponsored by NASA, IPAC or Caltech. NED asks that work using it acknowledge it ("This research has
made use of the NASA/IPAC Extragalactic Database (NED), which is funded by the National
Aeronautics and Space Administration and operated by the California Institute of Technology"),
each value cited from its reference, as the README's
[Terms of use](https://github.com/glitchr-omnisense/omnilab-ned#terms-of-use) says. Whoever runs
the package is responsible for complying with them and for any use made of what it returns: see
the README's [Responsibility](https://github.com/glitchr-omnisense/omnilab-ned#responsibility)
section.
