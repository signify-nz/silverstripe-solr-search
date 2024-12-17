# Subsites

This module is compatible with [silverstripe/subsites](https://github.com/silverstripe/silverstripe-subsites) for indexed classes that are associated with a subsite.

The default behaviour is to show only the results for content from the current subsite. To combine all subsite search results no matter the current subsite, set the following config:

``` php
Firesphere\SolrSearch\States\SubsiteState:
  combine_subsite_search: true
```

Indexed classes that do not have a SubsiteID field will always appear in search results on all subsites.

## Files

If indexing files, note that individual files are not directly associated with a SubsiteID. Folders can be set to specific subsites, but the files within those folders do not automatically inherit that information. If you need to filter files by the subsite set on the folders they are in, add the following config to inherit the SubsiteID from the parent Folder:

``` yml
Silverstripe\Assets\File:
  extensions:
    - Firesphere\SolrSearch\Extensions\FileSubsiteSearchExtension
```

In addition, Files are given the default SubsiteID of 0 - which means that they are all associated with the main site when not specified by the parent folder. The subsite module adds the following note as help text on the 'SubsiteID' field visible in folder settings: 
> Folders and files created in the main site are accessible by all subsites.

The default behaviour will follow this description and display all main site files on all subsites. To prevent main site files from appearing on subsites, set the following config:

``` php
Firesphere\SolrSearch\States\SubsiteState:
  share_main_files: false
```
