# Changelog

All notable changes to this project will be documented in this file, in reverse chronological order by release.

## 2.0.0 - 2017-02-22

### Added

- [#8](https://github.com/zfcampus/zf-composer-autoloading/pull/8) extracts the classes
  `ZF\ComposerAutoloading\Help`, `ZF\ComposerAutoloading\Command\Enable`, and
  `ZF\ComposerAutoloading\Command\Disable` from the `ZF\ComposerAutoloading\Command` class,
  which now delegates to each of them to perform its tasks.

### Changes

- [#8](https://github.com/zfcampus/zf-composer-autoloading/pull/8) renames the
  script from `autoload-module-via-composer` to `zf-composer-autoloading`.

- [#8](https://github.com/zfcampus/zf-composer-autoloading/pull/8) renames the
  `Command::__invoke()` method to `Command::process()`.

- [#8](https://github.com/zfcampus/zf-composer-autoloading/pull/8) adds a dependency
  on zend-stdlib in order to facilitate colorized console reporting, and allow
  testing.

### Deprecated

- Nothing.

### Removed

- Nothing.

### Fixed

- Nothing.

## 1.1.1 - 2017-02-22

### Added

- Nothing.

### Deprecated

- Nothing.

### Removed

- Nothing.

### Fixed

- [#5](https://github.com/zfcampus/zf-composer-autoloading/pull/5) fixes how
  the command creates the path to the module source directory; previously, it
  was hard-coded, and did not take into account the `-p`/`--modules-path`
  argument created in [#2](https://github.com/zfcampus/zf-composer-autoloading/pull/2).

- [#6](https://github.com/zfcampus/zf-composer-autoloading/pull/6) adds
  validation for the number of arguments, ensuring that no flags have empty
  values.

- [#7](https://github.com/zfcampus/zf-composer-autoloading/pull/7) adds
  validation of the composer binary in a cross-platform way; an exception is
  now raised if it is not executable.

## 1.1.0 - 2017-02-16

### Added

- [#2](https://github.com/zfcampus/zf-composer-autoloading/pull/2) adds the
  flags `-p`/`--modules-path`, allowing the user to specify the directory
  holding the module/source tree for which autoloading will be provided.

### Deprecated

- Nothing.

### Removed

- Nothing.

### Fixed

- Nothing.

## 1.0.0 - 2016-08-12

Initial release.

### Added

- Nothing.

### Deprecated

- Nothing.

### Removed

- Nothing.

### Fixed

- Nothing.
