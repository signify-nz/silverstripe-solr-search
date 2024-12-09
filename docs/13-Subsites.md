# Subsites

This module is compatible with [silverstripe/subsites](https://github.com/silverstripe/silverstripe-subsites).

The default behaviour is to show only the results for content from the current subsite. To combine all subsite search results no matter the current subsite, set the following config:

``` php
Firesphere\SolrSearch\States\SubsiteState:
  combine_subsite_search: true
```
