# FAQ

### What do you mean not fast enough?

All indexing, as well as the search, requires disk space. If the disk can not respond fast enough to a write,
either PHP or Java will stop and throw an error

### Do you support synonyms?

Yes! Including US to UK spelling synonyms by default!

### Fast?

Yes, very fast

### Compatible with the Fulltext Search Module?

99% and counting! Have a look at the compatibility module

### Why do I need to name my index?

You have a name yourself, don't you? It makes sense to name the index too.

### Only File storage?

Currently, this is indeed the only storage mode supported.

### My core.properties won't persist, what gives?

Most likely, you're running a vagrant or docker machine? And even more likely
running it on Linux.

A solution is to create a symlink in `/var/solr/data/{your-policy-name}/` to `/var/www/{yoursite}/.solr/conf`, so the
`conf` folder points to your locally-writable folder for Vagrant.

In your search.yml, add the following as the location of your FileStore config:
```yaml
Firesphere\SolrSearch\Services\SolrCoreService:
  store:
    path: '/var/solr/data'
```

That way, Solr will write its own files to the correct core folder where it can write, but your config can still live
inside your project.

Or, a better way for production environments is described in [Known issues](03-Known-issues.md)

### I would like a feature to be added!

I would like an issue to be created

### Dealing with errors

Especially when using facets, you should not redeclare fields to be filterable as well. If you run in to an error 
saying you have duplicate fields, check your configuration for overlaps
e.g. FilterFields does not have an overlap with FacetFields

### @package tag

The package tag is to help the API documentation to stay up to date.

We are aware it's a bit of overkill and redundant, but it does not have any impact on the actual code.
