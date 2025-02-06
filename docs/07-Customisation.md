# Customisation

## Extension points

All steps of the process, from index to searching, have extension points.

These extension points can be used to alter or update the respective steps.

Available extension points:

| Method | Used for | Available on |
| ------ | -------- | ------------ |
| `onBeforeSolrConfigureTask` | Alter the Configuration before running the configure task | `SolrConfigureTask` |
| `onConfigureIndex` | Operates after an index is added | `SolrConfigureTask` |
| `onAfterSolrConfigureTask` | Executes after Solr is configured via task. Can be used to check if the configuration is added for example | `SolrConfigureTask` |
| `onBeforeConfig` | Operates before a configuration is uploaded | `SolrConfigureTask` |
| `onBeforeInit` | Update initialisation features | `BaseIndex` |
| `onAfterInit` | Update initialisation features | `BaseIndex` |
| `onBeforeSearch` | Before executing the search, update the query | `BaseIndex` |
| `onAfterSearch` | Manipulate the results | `BaseIndex` |
| `updateSearchResults` | Manipulate the returned result object | `BaseIndex` |

## Updating the typemap

The type mapping is defined in [`typemap.yml`](../_config/typemap.yml).

To override parts of the typemap to use a different mapping, you can set the following in your application
yml:

```yaml
---
Name: MyTypemap
After:
  - '#SolrTypemap'
---
Firesphere\SolrSearch\Helpers\Statics:
  typemap:
    Varchar: htmltext
    "SilverStripe\\ORM\\FieldType\\DBVarchar": htmltext
    "DBVarchar": htmltext
```

Note that you need to set all three options, because of the classmapping that SilverStripe does.

## Custom `types.ss` and `schema.ss`

You need to place your custom `.ss` types/schema files in your custom application folder in the following path:

- for template files: `app/Solr/{SolrVersion}/templates`
- for extras files: `app/Solr/{SolrVersion}/extras`

### Set the custom paths to your templates and extras

Set the path to your custom template like so:
```yaml
Firesphere\SolrSearch\Services\SolrCoreService:
  paths:
    base_path: '%s/app'
```

When a base path is set, the template will automatically be selected based on your Solr Version.

Where you should select the correct `SolrVersion` from versions 4, 5 or 7, depending on the Solr version
you are using
- 4: Only Solr 4
- 5: Solr version >=5.0 and <7.0
- 7: Solr7 and up

#### Available field maps for YML

| FieldType | Indexed | Returnable | Case-sensitive |
| --------- | ------- | ---------- | -------------- |
| string | Yes | Yes | Yes |
| tint | Yes | No | N/A |
| htmltext | Yes | Yes | No |
| text | Yes | Yes | No |
| boolean | Yes | Configurable | N/A |
| tdate | Yes | Configurable | N/A |
| tfloat | Yes | Configurable | N/A |
| tdouble | Yes | Configurable | N/A |
 

#### Usage of %s

All paths are determined based on the `Director::baseFolder()` method. We use `%s`
so that the actual full path to the templates is resolved correctly to the base folder.

This is to avoid complexity around installation location, as hard-coding `/var/www/mywebsite` may not always
be correct.

### IMPORTANT

If you have a custom path, all files from the Solr version you choose, _need_ to exist in this folder!

This includes the `extras` folder in its entirety.

It is easiest to copy the entire `Solr` folder to your own application and alter what you need in there, leaving
everything else untouched. This will ensure that everything is in place.

## DataObject Parent Reindexing

There are many situations in which an indexed DataObject may be linked to other DataObjects that shouldn't be indexed on their own, but do impact the indexed content of the parent. A very common example of this is Elemental Blocks on a Page - the indexed content of the Page contains the content of the Blocks, and therefore the Page should be updated in the index when the Block is changed.

To implement this behaviour, add the `IndexedParentSolrUpdate` trait to the DataObject along with a `getIndexedParent` function that defines indexed parent. For example:

```php
class ExampleItem extends DataObject
{
    use IndexedParentSolrUpdate;

    public function getIndexedParent()
    {
        return $this->owner->getPage();
    }
}
```
