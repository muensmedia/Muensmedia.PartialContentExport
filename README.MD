# Muensmedia.PartialContentExport

## Export and import partial content instead of an entire site

With this package, you can export _partial content_ - page trees and even individual content elements from one Neos
instance into another. Think of it as a more flexible version of the built-in site export and import.

`Muensmedia.PartialContentExport` uses Neos' built-in Site Export / Import Service with an extended command line
interface to make more of its capabilities available.
If you have ever needed to manually copy content you prepared on a staging instance to your live instance, and exporting
the entire site is not an option, than this is the package for you!

## Features
- Export individual nodes and their descendants without exporting the entire site
- Import these nodes in a different Neos instance at the same or a different path

### Author

This package is developed by [MÜNSMEDIA GmbH](https://muensmedia.de/) from Magdeburg.

## Installation

Run this composer command to install the package
```bash
composer require --no-update muensmedia/partial-content-export
```
and then update composer: `composer update`

## Usage

The command line interface is heavily inspired by the original `site:export` and `site:import` commands.

### Export content

Example: Exporting a page or content element using its identifier.
```bash
./flow content:export --source 16e80b43-393b-42c6-8e1a-2f280640fbf0 --package-key "Muensmedia.Site" --site-name "site" --filename "easter-campaign"
```
This will export the node identified by `16e80b43-393b-42c6-8e1a-2f280640fbf0` into a file `easter-campaign.xml` within
`Muensmedia.Site/Resources/Private/Content` directory. Additionally, a `Resources` directory containing all resources
used in the exported content will be created.

| Option           | Example                                                                                               | Explanation                                                                                                                                                                                                                              |
|------------------|-------------------------------------------------------------------------------------------------------|------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| source           | `16e80b43-393b-42c6-8e1a-2f280640fbf0`<br/>or<br/>`/sites/site/node-fwrkqocrc01hp/node-v76vwg2cymq7u` | The page or content element to export. Can be either a node identifier or a node path.                                                                                                                                                   |
| package-key      | `Muensmedia.Site`                                                                                     | Optional. The package to write the exported content xml into. If no package key is given, `--filename` must be an absolute path.                                                                                                         |
| filename         | `easter-campaign.xml`                                                                                 | Optional. The file name for the exported xml. Must be an absolute path if `--package-key` is not given.                                                                                                                                  |
| site-name        | `site`                                                                                                | Optional. The site to export from. Only needed if a node identifier is given in `--source`.                                                                                                                                              |
| tidy             | `0`                                                                                                   | Optional. Exports formatted xml (to be more easily readable). Defaults to `true`                                                                                                                                                         |
| node-type-filter | `!Neos.Neos:Page,Neos.Neos:Text`                                                                      | Optional. Allows limiting the export to certain node types. See the documentation for the original [site:export](https://neos.readthedocs.io/en/stable/References/CommandReference.html#neos-neos-site-export) command for more details. |


### Import content

Example: Simply importing content to the same location in the content tree that it was exported from.
```bash
./flow content:import --package-key "Muensmedia.Site" --filename "easter-campaign"
```
The original location of the exported content is stored in the xml file. If no other location is specified, the content
will be imported at the same location. Content already existing at that location will be merged with the new one.


| Option        | Example                                                                                               | Explanation                                                                                                                                                                                                                                           |
|---------------|-------------------------------------------------------------------------------------------------------|-------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| package-key   | `Muensmedia.Site`                                                                                     | Optional. The package to fetch the exported content xml from. If no package key is given, `--filename` must be an absolute path.                                                                                                                      |
| filename      | `easter-campaign.xml`                                                                                 | Optional. The file name for the exported xml. Must be an absolute path if `--package-key` is not given.                                                                                                                                               |
| target-path   | `16e80b43-393b-42c6-8e1a-2f280640fbf0`<br/>or<br/>`/sites/site/node-fwrkqocrc01hp/node-v76vwg2cymq7u` | Optional. The page or content item to import into (i.e. the parent node new content will be inserted into.) If the root node of the imported content already exists, it is also valid to specify its node identifier or path instead of its parent's. |

### Current limitations

**This package currently cannot be used to duplicate or move content (either to the same or a different site within the
same installation).** This means that you cannot re-import existing content to a different path to move it or create a
duplicate. As doing so would cause various problems within your Neos site, we added some basic sanity checks to prevent
this condition.

## Contributions

If you find any issues with this package or have ideas for future developments, please create an issue.
We will also gladly review and accept pull requests to improve this package.

## License
See [License](LICENSE)
