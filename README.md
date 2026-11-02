# islandora-skosmos

Publish any Islandora taxonomy vocabulary as a linked-data authority. Choose a vocabulary in a form, and it gets permanent URIs, SKOS output, content negotiation and a [Skosmos](https://skosmos.org/) browser.

## How it works

```text
Drupal taxonomy ──> skos_export module ──> triple store ──> Skosmos at /skosmos
                          │
                          └──> /<namespace>/<id>: Turtle, JSON-LD, or 303 to Skosmos
```

- `skos_export/`: Drupal module with the settings form, URI resolver, Drush commands and cron sync.
- `docker-compose.skosmos.yml`: Skosmos container with Traefik routing at `/skosmos`.

Drupal stays the place where terms are edited. Each sync replaces the vocabulary's graph in the store, and Skosmos reads from that store.

## Requirements

- An Islandora site on Drupal 10.3 or later, or Drupal 11, with Drush 12 or later (attribute-based commands)
- A Docker Compose stack with Traefik, such as ISLE
- Blazegraph, which ISLE provides, with a quads namespace (step 3 below)

## Add this to your Islandora instance

1. **Add the module.** Copy or bind-mount `skos_export` into `web/modules/custom`.

   Composer through a `vcs` repository is **untested**. `composer.json` sits at the repository root and the module is in a subfolder, so check the installed path if you try it. A Composer install also needs an image rebuild on ISLE, because `vendor/` and contrib modules are baked in.

2. **Enable it.**

   ```bash
   drush en skos_export
   ```

3. **Create the store namespace.** Run this from a container on the stack's network:

   ```bash
   curl -X POST -H 'Content-Type: text/plain' http://blazegraph:8080/bigdata/namespace --data-binary $'com.bigdata.rdf.sail.namespace=skosmos\ncom.bigdata.rdf.store.AbstractTripleStore.quads=true\ncom.bigdata.rdf.store.AbstractTripleStore.textIndex=true\ncom.bigdata.rdf.sail.truthMaintenance=false\n'
   ```

4. **Add the Skosmos service.** Add `docker-compose.skosmos.yml` to `COMPOSE_FILE`. Don't start it until you have a config file from the next section.

5. **Check routing.** The compose labels route `/skosmos` on `${DOMAIN}` and strip the prefix. The labels assume Traefik's TLS setup, so match your other services if yours differs. Concept URIs are ordinary Drupal routes and need no Traefik change, as long as the namespace path doesn't collide with a path alias.

## Serve a vocabulary with Skosmos

1. Open **Configuration > Web services > SKOS export** at `/admin/config/services/skos-export`. This needs the `administer skos export` permission.
2. Tick the vocabulary and give it a URI namespace ending in `/` or `#`, such as `/vocab/`. Set **Public URL** too, because cron sync needs a host. Saving adds the identifier field if needed.
3. Mint identifiers, generate the Skosmos config and publish:

   ```bash
   drush --uri=https://example.org skos:mint
   drush --uri=https://example.org skos:config > skosmos-config.ttl
   drush --uri=https://example.org skos:sync
   ```

4. Point Skosmos at the config and start it:

   ```bash
   export SKOSMOS_CONFIG=./skosmos-config.ttl
   docker compose up -d skosmos
   ```

5. Verify it, replacing `<short-name>` and `<id>`:

   ```bash
   curl -I https://example.org/skosmos/<short-name>/en/      # 200
   curl -H 'Accept: text/turtle' https://example.org/vocab/<id>   # concept as Turtle
   curl -H 'Accept: application/ld+json' https://example.org/vocab/<id>   # concept as JSON-LD
   curl -I https://example.org/vocab/<id>                    # 303 to Skosmos
   curl https://example.org/skos-export/<vocabulary>         # full Turtle dump
   ```

Re-run `skos:config` and restart Skosmos whenever the list of published vocabularies changes.

## URIs

| Request to `/vocab/<id>` | Response |
|---|---|
| `Accept: text/turtle` | Concept as Turtle |
| `Accept: application/ld+json` | Concept as JSON-LD |
| Anything else | 303 to Skosmos, or the term page without Skosmos |
| Unpublished term | 410 |
| Unknown or deleted identifier | 404 |

`/vocab` does the same for the scheme, except JSON-LD. `/skos-export/<vocabulary>` always returns the full Turtle dump and requires `access content`.

## Configuration

Site settings in `skos_export.settings`:

| Key | Default | Purpose |
|---|---|---|
| `public_url` | empty | Public site URL. Empty resolves against the request host. |
| `skosmos_path` | `/skosmos` | Public Skosmos path. Empty if unused. |
| `service_name` | `Vocabularies` | Skosmos service name. |
| `store_type` | `blazegraph` | `blazegraph` or `graph_store`. |
| `store_url` | Blazegraph `skosmos` namespace | Write URL, reachable from Drupal. |
| `sparql_endpoint` | Blazegraph `skosmos` namespace | Query URL, reachable from Skosmos. |

Per vocabulary, under `vocabularies.<machine_name>`:

| Key | Purpose |
|---|---|
| `short_name` | Skosmos short name. |
| `base_uri` | Concept namespace, absolute or a path, ending in `/` or `#`. |
| `title` | Concept scheme title. |
| `language` | Label language tag. |
| `id_field` | Text field holding the permanent identifier. |
| `id_prefix` | Prefix for minted identifiers. |
| `match_field` | Optional link field published as `skos:exactMatch`. |
| `alt_label_field` | Field published as `skos:altLabel`. |
| `scope_note_field` | Field published as `skos:scopeNote`. |
| `history_note_field` | Field published as `skos:historyNote`. |

The term description is published as `skos:definition`, and term parents become `skos:broader`. **Every mapped field is public.** Keep internal notes in an unmapped field and restrict its access in Drupal.

`SKOSMOS_TAG` and `SKOSMOS_CONFIG` set the compose image tag and config path.

## Commands

| Command | Purpose |
|---|---|
| `drush skos:mint` | Mint identifiers for terms that lack one. |
| `drush skos:config` | Print the Skosmos config to stdout. |
| `drush skos:sync [vocabulary]` | Replace the graph for one vocabulary, or all of them. |

Term changes mark the vocabulary as changed, and the next cron run replaces its graph. A failed sync stays queued. Cron needs `public_url`, or run `drush --uri=https://example.org cron`.

## Permanent identifiers

Published URIs are promises:

- Minted identifiers are random and opaque, carry no meaning, and are never reused.
- Saving a term mints a missing identifier. Changes to an existing one are reverted and logged.
- Terms with no identifier are left out of every export.
- **To retire a term, unpublish it**, and its URI answers 410. Deleting a published term makes it answer 404.
- Don't change a namespace after publishing.
- Identifiers are term content, so they don't travel with config. Move them with the terms, or mint once on the environment that publishes.

## Other stores

Set **Store protocol** to *SPARQL Graph Store Protocol*, point **Store write URL** at the graph endpoint (for Fuseki, `http://fuseki:3030/skosmos/data`), and point **SPARQL endpoint** at the query endpoint.

**This path is untested.** Only Blazegraph has been tested so far. Keep the write URL off the public network, because the module sends no credentials.

## Troubleshooting

| Symptom | Check |
|---|---|
| A term is missing | Does it have an identifier? Run `skos:mint`. |
| Cron doesn't sync | Set `public_url`, or run cron with `--uri`. |
| Concept URI returns 404 | Check the identifier, then run `drush cr` after changing namespaces. |
| Skosmos shows no vocabulary | Re-run `skos:config`, restart Skosmos, then run `skos:sync`. |
| `/skosmos` returns 502 | Skosmos may still be starting. Check `docker compose ps`. |

## Limitations

- Composer installation and the Graph Store path are untested.
- The upstream Skosmos image is linux/amd64 here, so ARM hosts run it under emulation, which is slower.
- A namespace path can collide with a Drupal path alias.
- Published URIs last only as long as your domain does. Consider a persistent identifier service before announcing them.

## Licence

GPL-2.0. See `LICENSE`. `composer.json` declares `GPL-2.0-or-later`.
