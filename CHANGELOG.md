# Changelog

## [Unreleased]

## [1.0.0] - 2026-11-13

The first stable release: it installs with Composer's default minimum-stability (stable).

### Added

- Omnilab NED: an object of the NASA/IPAC Extragalactic Database - a galaxy, a quasar, a cluster, a
  radio source - by any of its names: its preferred name, type, J2000 position with its uncertainty,
  redshift with its uncertainty and quality, galactic coordinates, and how many references,
  photometric points, redshift-independent distances, images and spectra NED holds for it; the
  objects of a cone on the sky - through NED's ObjectLookup and TAP services, no key needed.

### Release

- Requires `omnisense/omnilab` `^1.0`, stable versions, where it required `@dev` ones:
  `composer require omnilab/ned` installs in a project at Composer's default minimum-stability
  (stable). `extra.branch-alias` names the branch (`dev-1.x`: `1.x-dev`).
- CI: `.github/workflows/ci.yml` (glitchr-studio/ci's php-package.yml, pinned: the package
  installed alone, PHPUnit, PHPStan against its baseline, a consumer install) and `release.yml`
  (that CI on the tag, then glitchr-studio/ci's php-release.yml); `phpunit.xml.dist` runs the
  package's own tests.
- README: the install line and a first call.
