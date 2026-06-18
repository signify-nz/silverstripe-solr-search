# SilverStripe Solr Search

Advanced, Solr-powered search for SilverStripe 4 and 5, built on
[Solarium](https://solarium.readthedocs.io). Define what to index in PHP,
configure connections in YAML, and query Solr with a fluent API.

> Based on [firesphere/solr-search](https://codeberg.org/Firesphere/silverstripe-solr).
> This is the `signify-nz` maintained fork.

## Features

- **Code-defined indexes** — declare indexed classes and fields in a PHP index class.
- **Rich field types** — full-text, filter, facet, sort, stored and copy fields.
- **Faceting, boosting, fuzzy search, elevation** and advanced filters/excludes.
- **Spellcheck & suggestions** for "did you mean" experiences.
- **View-permission aware** — results are filtered by each member's `canView` rights.
- **`ShowInSearch` handled automatically** — hidden pages/files are removed from the core.
- **Queued indexing** via `silverstripe/queuedjobs`, indexing live content only.
- **Subsites, Fluent, Elemental and Fulltext-Search compatibility** submodule support.
- **Pluggable config stores** — file-based or HTTP POST to a remote Solr.
- Works with **Solr 4 (backward compatible), 8 (default) and 9**.

## Requirements

- PHP 7.3+
- SilverStripe Framework 4 or 5
- [symbiote/silverstripe-queuedjobs](https://github.com/symbiote/silverstripe-queuedjobs)
- A running Solr instance (4 / 8 / 9) reachable from the application
- [Solarium](https://solarium.readthedocs.io) (installed automatically via Composer)

## Installation

```bash
composer require signify-nz/silverstripe-solr-search
```

See [docs/01-Installation.md](docs/01-Installation.md) for details.

## Quick start

### 1. Configure the Solr connection

Connection details are set in YAML. Defaults assume a Solr instance on
`localhost:8983`, so this step can be skipped for local development.

```yaml
# app/_config/search.yml
Firesphere\SolrSearch\Services\SolrCoreService:
  config:
    endpoint:
      myhostname:
        host: solr.example.com
        port: 8983
        timeout: 10
  store:
    path: '.solr'
```

See [docs/03-Set-up-and-Configuration.md](docs/03-Set-up-and-Configuration.md)
for authentication, config stores and all available options.

### 2. Define an index

Create an index extending `Firesphere\SolrSearch\Indexes\BaseIndex`. The
`init()` method declares what is indexed; `getIndexName()` names the Solr core.

```php
use Firesphere\SolrSearch\Indexes\BaseIndex;
use SilverStripe\Assets\File;
use SilverStripe\CMS\Model\SiteTree;

class MyIndex extends BaseIndex
{
    public function init()
    {
        $this->addClass(SiteTree::class);
        $this->addClass(File::class);

        $this->addFulltextField('Title');
        $this->addFulltextField('Content');

        $this->addFilterField('ClassName');
    }

    public function getIndexName()
    {
        return 'mysite-search';
    }
}
```

### 3. Configure the core and index your content

```bash
# Push the generated schema/config to Solr and (re)create the core
vendor/bin/sake dev/tasks/SolrConfigureTask

# Queue a job to index your content
vendor/bin/sake dev/tasks/SolrIndexTask
```

Make sure the queued-jobs runner is processing jobs (and restart long-running
workers after deploying code changes, so they pick up the new classes).

### 4. Run a search

```php
use Firesphere\SolrSearch\Indexes\BaseIndex;
use Firesphere\SolrSearch\Queries\BaseQuery;
use SilverStripe\Core\Injector\Injector;

class SearchPageController extends PageController
{
    public function getResults()
    {
        $term = $this->getRequest()->getVar('Search');
        if (!$term) {
            return null;
        }

        /** @var BaseIndex $index */
        $index = Injector::inst()->get(MyIndex::class);

        $query = Injector::inst()->get(BaseQuery::class);
        $query->addTerm($term);
        $query->setStart((int) $this->getRequest()->getVar('start'));

        return $index->doSearch($query); // returns a SearchResult
    }
}
```

### 5. Render the results

```html
<% with $Results %>
    <% if $TotalItems %>
        <p>$TotalItems results</p>
        <% loop $PaginatedMatches %>
            <h3><a href="$Link">$Title</a></h3>
            <p>$Excerpt</p>
        <% end_loop %>
        <% include Pagination %>
    <% else %>
        <p>No results found.</p>
    <% end_if %>
<% end_with %>
```

A fuller example (facets, sorting, spellcheck) is in
[docs/04-Searching.md](docs/04-Searching.md).

## Tasks

| Task                    | Purpose                                                                 |
|-------------------------|-------------------------------------------------------------------------|
| `SolrConfigureTask`     | Generate and upload the core configuration/schema to Solr.              |
| `SolrIndexTask`         | Queue a job to (re)index content into existing cores.                   |
| `FullSolrIndexTask`     | Queue a full reindex of all indexes and classes.                        |
| `ClearDirtyClassesTask` | Re-process records that previously failed to index (see Dirty classes). |
| `ClearErrorsTask`       | Clear recorded indexing errors.                                         |

> Indexing reads from the **live** stage, so only published content is added to
> the search index. A standard `SolrIndexTask` adds/updates documents but does
> not clear the core, so after changing index definitions run `SolrConfigureTask`
> (or a clearing reindex) to drop stale documents.

## Key concepts

- **`ShowInSearch`** is managed by the module. Setting it to `0`/false removes the
  page or file from the core via `onAfterPublish`/`onAfterWrite` or the next index
  run — do **not** add it as a custom indexed field, as that causes unexpected
  behaviour. See [docs/03-Set-up-and-Configuration.md](docs/03-Set-up-and-Configuration.md).
- **View permissions** — each document stores a view-status field so results are
  filtered to what the current member may `canView`. See
  [docs/11-View-Permissions.md](docs/11-View-Permissions.md).
- **Dirty classes** — records that fail to push to Solr are tracked so they can be
  retried rather than silently lost. See [docs/12-Dirty-classes.md](docs/12-Dirty-classes.md).
- **Config stores** — `FileConfigStore` (local path) or `PostConfigStore` (HTTP POST
  to a remote Solr). See [docs/06-Advanced-Options/06-Stores.md](docs/06-Advanced-Options/06-Stores.md).

## Documentation

Full documentation lives in the [docs folder](docs/index.md):

- [Installation](docs/01-Installation.md) · [Solr](docs/02-Solr.md) · [Setup & configuration](docs/03-Set-up-and-Configuration.md)
- [Searching](docs/04-Searching.md) · [Spellcheck](docs/05-Spellcheck.md) · [Customisation](docs/07-Customisation.md)
- Advanced options: [Faceting](docs/06-Advanced-Options/01-Faceting.md), [Boosting](docs/06-Advanced-Options/02-Boosting.md), [Fuzzy search](docs/06-Advanced-Options/03-Fuzzy-search.md), [Elevation](docs/06-Advanced-Options/04-Elevation.md), [Filters / Excludes](docs/06-Advanced-Options/05-Filters-excludes.md), [Stores](docs/06-Advanced-Options/06-Stores.md)
- [CMS usage](docs/08-CMS-Usage.md) · [Debugging](docs/09-Debugging.md) · [Suggestions](docs/10-Suggestions.md) · [View permissions](docs/11-View-Permissions.md) · [Dirty classes](docs/12-Dirty-classes.md) · [Subsites](docs/13-Subsites.md)
- Submodules: [Fulltext Search compatibility](docs/14-Submodules/01-Fulltext-Search-Compatibility.md), [Fluent](docs/14-Submodules/03-Fluent.md), [Member-based permissions](docs/14-Submodules/04-Member-based-permissions.md), [Elemental](docs/14-Submodules/05-Elemental.md)

Please read the documentation before raising questions — most are answered there.

## Solr version support

| Solr version | Status |
| --- | --- |
| 4 | Backward compatible |
| 8 | Default / recommended |
| 9 | Supported |

## Solarium

This module is built on [Solarium](https://solarium.readthedocs.io); its
documentation is a useful reference for lower-level query behaviour.

## Contributing

Contributions are welcome — please raise an issue and, ideally, an accompanying
pull request. See the [code of conduct](docs/15-Contributing/01-Code-of-Conduct.md)
and [contributing guide](docs/15-Contributing/02-Contributing.md).

## Security

Please report security issues responsibly as described in our
[security policy](SECURITY.md).

## License

[LGPL v3](LICENSE.md).

## Disclaimer

If this module breaks your website, you get to keep all the pieces.
