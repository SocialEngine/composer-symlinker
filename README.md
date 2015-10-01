Composer Symlinker Plugin
==================

This [Composer](http://getcomposer.org/) plugin allows you to install packages as local symbolic links, with the option
to fallback to other install options. This allows you to locally develop a package and its dependencies in parallel.

This has been discussed as a possible improvement to Composer, but movement has been slow. See composer/composer#1229,
and composer/composer#4011 for example discussions.

This is a fork of [piwi/composer-symlinker](https://github.com/piwi/composer-symlinker) with some enhancements. 
Specifically:
- Allow for relative paths (helpful if you develop in a virtual machine, but edit code on your host machine)
- Fall back to other install options, if a package can't be found locally.
- Allow for other installers to give the correct install path. (Useful when using packages that install to non-vendor 
    locations)

## Setup

First, add the plugin as a dependency in your `composer.json`:

```json
"socialengine/composer-symlinker": "~1.0"
```

Next, define local paths to your packages in an `extra` section of your `composer.json`. You have two options on how to
declare the paths:

- `local-dirs`: list out directories to look in for packages. When scanning, these paths will be combined with 
    `vendor/package`.
- `local-packages`: a key-value object, where the key is the package name, and the value is a path to that package. The
    paths can be relative to the `composer.json`.

If you want to restrict which vendors to scan for locally, you can add a `local-vendors` list.
 
If a package is not found locally, the plugin will fall back to other install methods (like packagist/git).

### Example Extra Section

```json
"extra": {
    "local-dirs": [
        "/my/absolute/local/path1",
        "../../relative/local/path2"
    ],
    "local-vendors": [
        "vendor1",
        "vendor2"
    ],
    "local-packages": {
        "vendor/package1": "/my/absolute/path/to/vendor/package1",
        "vendor/package2": "../relative/path/to/vendor/package2"
    }
}
```

## Example Setup

Let's say we want to work on a project named `MyProject` base on three dependencies:
`MyPackage1` and `MyPackage2` which are some of our packages, and a third-party
`ExternalPackage` which is not.

Further, `MyPackage1` is located at `/opt/php/MyVendor/MyPackage1` and `MyPackage2` can be found at `../my-package-2`.

We can configure our `composer.json` like so:

```json
"require": {
    "MyVendor/MyPackage1": "3.6.1",
    "MyVendor/MyPackage2": "0.10",
    "OtherVendor/ExternalPackage": "2.4.6"
},
"require-dev": {
    "socialengine/composer-symlinker": "~1.0"
},
"extra": {
    "local-dirs": "/opt/php/",
    "local-packages": {
        "MyVendor/MyPackage2": "../my-package-2"
    }
}
```

So, when developing on our local box, `composer install` will install our packages as symlinks to their respective
paths. If use the same `composer.json` on a box where the paths aren't valid, the packages will be installed
normally.  

## Windows users warning

The plugin uses the internal [`symlink()`](http://php.net/symlink) PHP function.
See *Windows* restrictions on the manual.

## License

This is released under the [MIT License](https://github.com/SocialEngine/composer-symlinker/blob/master/LICENSE).
