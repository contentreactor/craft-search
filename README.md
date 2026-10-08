# ContentReactor Search

Search for the pages [Field Value Parser](https://github.com/marcusgaius/field-value-parser) parses. Pages' searchable content is indexed with the `SEARCH` purpose, and kept up to date from the queue as their content, or the content of anything they include, changes.

- **Indexes:** a database index on MySQL or PostgreSQL, or Elasticsearch. The database index uses the database's full-text search (a `FULLTEXT` index on MySQL, a GIN index over a `tsvector` on PostgreSQL), and looks for short words anywhere in pages instead. On PostgreSQL, each site's pages are stemmed in its language, so `Konzerte` finds `Konzert`. Plugins can register their own with `Search::EVENT_REGISTER_INDEX_TYPES`.
- **Facets:** values search results can be filtered and counted by.
- **Excluding pages:** by section, category group, volume or entry type, or per page with a Hide from Search field.
- **Pinned results:** pages shown first for a search term.
- **Synonyms:** groups of words that match each other, e.g. `concert, gig, show`.
- **Search analytics:** what's searched for, and what finds nothing, per site and period.
- **Search results endpoint:** a site URI rendering results as HTML, or JSON with `Accept: application/json`.

## Requirements

Craft CMS 5.9+ and `marcusgaius/field-value-parser`, whose parsing library it builds on. It has a control panel section of its own and doesn't need the Field Value Parser plugin's screens or settings.

## Installation

```bash
composer require contentreactor/craft-search
php craft plugin/install cr-search
```

## Setup

In the control panel, **Search → Setup** picks and checks an index, the indexed element types and the endpoint. `craft cr-search/setup` does the same from the command line.

```
craft cr-search/index/rebuild [--site-id=…]
craft cr-search/index/clear [--site-id=…]
```

## Configuration

Settings can be overridden in `config/cr-search.php`. Facets can only be set there, as changing them requires rebuilding the index:

```php
return [
    'facets' => [
        // A path of field and attribute handles resolved on each page, as a keyword
        'units' => 'units.id',
        // Paths can be scoped to nested entries of a type, like eager-loading paths
        'speakers' => 'blocks.talk:speaker.title',
        'published' => ['type' => 'date', 'value' => 'postDate', 'label' => 'Published', 'width' => 'full'],
    ],
];
```

A facet's `width` (`auto`, `quarter`, `third`, `half` or `full`) is how much of a row its filter wants in a search form. The baseline search page follows it, and templates can read it as `facet.width`.

### PostgreSQL languages

On PostgreSQL, the database index stems words and leaves out stopwords in each site's language, with PostgreSQL's built-in text search configuration for it (`german` for `de-AT`, `english` for `en-US`, and so on). Languages without one use `simple`, which does neither. Other configurations, e.g. ones you've created with extra dictionaries, can be set per language:

```php
return [
    'searchIndex' => [
        'class' => \ContentReactor\Search\Indexes\DatabaseIndex::class,
        'textSearchConfigs' => ['de' => 'german_unaccent', 'pl' => 'polish'],
    ],
];
```

Rebuild the index after changing these, or a site's language.

## Templates

```twig
{# A search form with facet options, kept in sync with the request #}
{{ crSearchForm({facets: ['units']}) }}

{# Results, with request-style params: `q`, `filters` and `page` #}
{% set results = craft.crSearch.search({q: craft.app.request.getQueryParam('q')}) %}
```

`cr-search/results` and `cr-search/form` are the baseline templates; **Search → Settings** can replace them with your own.

## Search analytics

Searches from the search results endpoint and GraphQL are logged with their term, site and result count, and nothing identifying who searched. **Search → Analytics** lists the top searches and the searches that found nothing, for users with the *View search analytics* permission.

Searches from Twig or PHP are only logged when asked to, so searches like a page's related pages don't count:

```twig
{% set results = craft.crSearch.search({q: craft.app.request.getQueryParam('q'), track: true}) %}
```

`logSearches` turns logging off, and `searchLogDays` sets how many days searches are kept (90 by default, `0` for forever). Older ones are deleted with Craft's garbage collection.

## Synonyms

**Search → Synonyms** holds groups of words that match each other, for all sites or one, for users with the *Manage synonyms* permission. A search for any of a group's words finds pages with the others too, so `gig tickets` finds pages about concert tickets. Synonyms are single words, as searches match word by word, and they take effect without rebuilding the index. Searches that found nothing, in **Search → Analytics**, are a good place to find them.

A query's own synonyms can be set instead of the site's, e.g. from `Search::EVENT_BEFORE_SEARCH`:

```php
$event->query->synonyms = [['concert', 'gig', 'show']];
```

## Excluding pages

Pages can be kept out of search, from least to most specific:

- **Sources:** **Search → Settings → Excluded Sources** leaves out the pages of sections, category groups and volumes (`excludedSources`, as `section:{uid}` and the like).
- **Entry types:** **Excluded Entry Types** leaves out entries of those types in any section (`excludedEntryTypes`, as UIDs).
- **Single pages:** add a *Hide from Search* field to a field layout, and pages with it turned on are removed from the index once they're saved.

Changing the excluded sources or entry types in the control panel rebuilds the index. Plugins can decide with their own rules too:

```php
use ContentReactor\Search\Events\DefineIndexableEvent;

Event::on(Search::class, Search::EVENT_DEFINE_INDEXABLE, function (DefineIndexableEvent $event) {
    // e.g. leave out thank-you pages, wherever they are
    $event->isIndexable = $event->isIndexable && !str_starts_with((string)$event->page->slug, 'thank-you');
});
```

## Pinned results

**Search → Pinned Results** shows chosen entries first, in a set order, for a search term on all sites or one, for users with the *Manage pinned results* permission. They're shown when a search's term is exactly the pinned term, ignoring case and extra spaces, and only when they match the search's filters. Pinned pages take the first places of the results, so pages of results, totals and facet counts include them once. Search → Analytics links searches that found nothing to pinning results for them.

Results say which of their pages are pinned, e.g. to mark them:

```twig
{% for element in results.elements %}
    {{ element.title }}{% if results.isPinned(element) %} (Featured){% endif %}
{% endfor %}
```

Any pages can be pinned from code, for a query of their own, with `$event->query->pinnedElementIds` in `Search::EVENT_BEFORE_SEARCH`. An empty array shows none.

## GraphQL

Schemas with the **Search** component can use the `crSearch` query. Searches are kept to the sites, sections, volumes, category groups and tag groups the schema can read, so totals and facet counts only include what it can see.

```graphql
{
  crSearch(q: "forest", filters: [{facet: "published", min: "2024-01-01"}], page: 1) {
    total
    pageCount
    elements { id title url }
    pinnedIds
    facets { handle label type width options { value label count } }
  }
}
```

`filters` take `values` (any of which matches) or a `min` and/or `max` range. `facets` limits which facets are counted, and `limit` changes the results per page.

## Events

`Search::EVENT_BEFORE_SEARCH` and `Search::EVENT_AFTER_SEARCH` fire around every search, including the ones counting facet values for search forms (those have a `limit` of 0):

```php
use ContentReactor\Search\Events\SearchEvent;
use ContentReactor\Search\Services\Search;
use yii\base\Event;

// Change the query. Filters are added the way requests send them.
Event::on(Search::class, Search::EVENT_BEFORE_SEARCH, function (SearchEvent $event) {
    $event->query->filters['published'] = ['min' => '2024-01-01'];

    // Or answer the search without the index, e.g. from a cache
    // $event->results = $cachedResults;
});

// Change or replace the results
Event::on(Search::class, Search::EVENT_AFTER_SEARCH, function (SearchEvent $event) {
    $event->results->elements = array_filter($event->results->elements, fn($element) => $element->enabled);
});
```

## Editions

Search comes in two editions, from the Plugin Store:

| | Lite | Pro |
|---|:---:|:---:|
| Database index, on MySQL and PostgreSQL | ✓ | ✓ |
| Facets, excluded pages, the search results endpoint, GraphQL | ✓ | ✓ |
| Elasticsearch index | | ✓ |
| Synonyms | | ✓ |
| Pinned results | | ✓ |
| Search analytics | | ✓ |

On Lite, the Pro screens aren't listed, and searches leave synonyms, pinned results and logging out. A configured Elasticsearch index leaves search off on Lite rather than using it.

## Support

The plugin registers the `cr-search` handle, the `ContentReactor\Search` namespace and `{{%contentreactor_search_*}}` tables.

Report issues at https://github.com/contentreactor/craft-search/issues, or write to support@contentreactor.com.

## License

The Craft License, see [LICENSE.md](LICENSE.md).
