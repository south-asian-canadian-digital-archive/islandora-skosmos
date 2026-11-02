# islandora-skosmos

Publish any Islandora taxonomy vocabulary as a linked-data authority: choose it in
a form, get permanent URIs, SKOS, content negotiation and a [Skosmos](https://skosmos.org/) browser.

- `skos_export/`: Drupal module with the settings form, URI resolver, Drush commands and cron sync.
- `docker-compose.skosmos.yml`: Skosmos behind Traefik at `/skosmos`.

## Install on an ISLE stack

1. Add the module. Either require this repository through a Composer `vcs`
   repository, or mount `skos_export` into `web/modules/custom`. Then run
   `drush en skos_export`. Rebuild the Drupal image if you used Composer.
2. Create a quads namespace in Blazegraph, from inside the stack's network:

   ```bash
   curl -X POST -H 'Content-Type: text/plain' http://blazegraph:8080/bigdata/namespace --data-binary $'com.bigdata.rdf.sail.namespace=skosmos\ncom.bigdata.rdf.store.AbstractTripleStore.quads=true\ncom.bigdata.rdf.store.AbstractTripleStore.textIndex=true\ncom.bigdata.rdf.sail.truthMaintenance=false\n'
   ```

3. At **Configuration > Web services > SKOS export** (permission
   `administer skos export`), tick the vocabularies to publish and give each a
   URI namespace such as `/vocab/`.
4. Publish:

   ```bash
   drush --uri=https://example.org skos:mint
   drush --uri=https://example.org skos:config > skosmos-config.ttl
   drush --uri=https://example.org skos:sync
   ```

5. Set `SKOSMOS_CONFIG=./skosmos-config.ttl`, add the compose file to
   `COMPOSE_FILE`, and start `skosmos`. Re-run `skos:config` when the vocabulary
   list changes.

The compose file only routes `/skosmos`. Concept URIs are ordinary Drupal routes,
so Traefik needs no change for them. Make sure the namespace path doesn't collide
with a path alias.

## Other stores

Set **Store protocol** to *SPARQL Graph Store Protocol* for stores such as Fuseki,
pointing **Store write URL** at the graph endpoint (for Fuseki,
`http://fuseki:3030/skosmos/data`), and **SPARQL endpoint** at the query endpoint.
Only the Blazegraph path has been tested so far. Keep the store's write URL off
the public network; the module sends no credentials.

## Keeping it in sync

Term changes mark the vocabulary as changed, and the next cron run replaces its
graph. Cron needs a host: set the public URL, or run `drush --uri=... cron`.
Without one, sync waits. A failed sync stays queued.

## Permanent identifiers

Published URIs are promises, so these rules are enforced or assumed:

- Identifiers are random and opaque, carry no meaning, and are never reused.
- Saving a term mints a missing identifier. Attempts to change one are reverted and logged.
- Terms with no identifier are left out of every export.
- Never delete a published term, because its identifier then answers 404. Unpublish it, and its URI answers 410.
- Identifiers are term content, so they don't travel with config. Move them with the terms, or mint once on the environment that publishes.
- Don't change a namespace after publishing.

## URIs

| Request to `/vocab/<id>` | Response |
|---|---|
| `Accept: text/turtle` | Concept as Turtle |
| `Accept: application/ld+json` | Concept as JSON-LD |
| Anything else | 303 to Skosmos, or the term page without Skosmos |

`/vocab` does the same for the scheme, except JSON-LD. `/skos-export/<vocabulary>`
always returns the full Turtle dump.

The upstream image is linux/amd64 only here, so ARM hosts run it under emulation.
