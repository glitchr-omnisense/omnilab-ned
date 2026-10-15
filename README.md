# omnilab/ned

NED, the NASA/IPAC Extragalactic Database, for [glitchr/omnilab](https://github.com/glitchr-studio/omnilab):
an object beyond our galaxy - a galaxy, a quasar, a cluster, a radio source... - by any of its
names: its preferred name, type, J2000 position with its uncertainty, redshift with its
uncertainty, quality and reference, its galactic coordinates, and how many references,
photometric points, redshifts, diameters, redshift-independent distances, images and spectra NED
holds for it; the objects of a cone on the sky (outside the contract). No key.

```php
$ned = (new NedSourceFactory($http))->create(['mailto' => 'support@glitchr.io']);
$m31 = $ned->entity('M31');                    // or 'Messier 031', 'ned:Messier 031', simbad:M 31
$m31->id;                                      // ned:Messier 031
$m31->kind;                                    // galaxy
$m31->property('redshift');                    // -0.000991 ± 0.000003
$m31->value('distance_measurements');          // 407 (NED-D's redshift-independent distances)
$ned->entity('3C 273')->kind;                  // quasar, z = 0.158338994
$ned->cone(197.4487623, -23.3839623, 0.1, 5, 'G');   // the galaxies around NGC 4993 (outside the contract)
```

```yaml
omnilab:
    sources:
        ned:
            factory: ned
            options:
                mailto: 'support@glitchr.io'   # in the User-Agent
```

An object is named in the core's `ned` scheme by its preferred name. omnilab/ned also reads a
`simbad` identifier, a name NED resolves when it knows it, so the `Collector` follows an object
from SIMBAD (or from Wikidata through SIMBAD) into NED.

See [docs/](docs/index.md) for what is read, how it maps, and what NED does not give here.

## Terms of use

NED is operated by IPAC at the California Institute of Technology under contract with NASA. It
asks that work using it acknowledge it: "This research has made use of the NASA/IPAC
Extragalactic Database (NED), which is funded by the National Aeronautics and Space
Administration and operated by the California Institute of Technology." Each value carries the
reference (bibcode) NED took it from, to be cited with it. No rate is published; NED's TAP
answers in 1 to 40 s, a large cone the slowest.

## Responsibility

This package is an independent, unofficial client: it is not affiliated with, endorsed by or
sponsored by NASA, IPAC or the California Institute of Technology. It reaches their service on behalf of whoever runs it, and it does not
redistribute their data beyond the short excerpts recorded as test fixtures. Its MIT licence
covers this package's code only and grants no right to the service or to its data.

Whoever installs and runs this package is responsible for complying with the terms above, for
obtaining whatever they require (an account, a key, a licence or subscription, an attribution or
a citation, a rate limit to respect), and for any use made of what it returns. The authors
publish it free of charge, make no commercial use of it, and accept no liability for how it is
used (see [LICENSE](LICENSE)).

License: MIT.
