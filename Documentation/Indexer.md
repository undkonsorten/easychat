# Setup TYPO3 Indexer

We are using the [TYPO3 extension _Index_](https://extensions.typo3.org/extension/index) to retrieve information (aka content and files) from the TYPO3 CMS and store it in a vector database (like Qdarnt) as a knowledge base (RAG) for the LLM.

Short version: see [Knowledge base (RAG) via EXT:index](../Readme.md#knowledge-base-rag-via-extindex) in the Readme.

## Detailed setup

1. Require the vector store bridge: `composer require symfony/ai-qdrant-store` (not installed by default,
   since not every EasyChat site uses a vector database).
2. Create a `tx_index_domain_model_configuration` record (EXT:index) on your site's root page — pick a
   *technology* (`Database` is fastest, `Frontend` renders real page markup), enable *content indexing*,
   and set the languages/levels to crawl. See [EXT:index's README](https://github.com/lochmueller/index)
   for the full field reference.
3. Create the two scheduler tasks EXT:index needs to actually run: `index:queue` (fills the queue) and
   `messenger:consume` fill `index` in the field **Argument** (processes it) — see EXT:index's README for details.
4. On your EasyChat *Configuration* record, set *Vector db* to `Qdrant`, fill in the connection fields
   (host, port, collection name, API key, embeddings model, and the embedding model's output *dimensions*
   — e.g. `1536` for `text-embedding-3-small`), and select the index configuration(s) from step 2 in the
   new *Index configurations* field.
   * By default the embeddings model is called on the **same API url/key as the chat LLM** above (just a
     different model id). If your embeddings model lives on a different endpoint/provider, set the
     optional *Embeddings API url* / *Embeddings API key* fields to override it.
   * Make sure the chatbot's **reaction** (setup step 3) uses exactly this EasyChat configuration —
     otherwise the chatbot searches a different collection, or none at all.

Once a scheduler run has indexed some pages, ask the chatbot a question whose answer only exists in your
site content — the agent will call the similarity-search tool automatically when relevant.

## Tell the model that the knowledge base exists

The similarity-search tool is registered with a deliberately generic description ("Searches for documents
similar to a query or sentence"), so **mention it in your *System message*.** Without that, some models
never reach for it, and safety-trained models in particular may refuse questions that merely *sound*
confidential ("what is our internal codename for …") instead of searching. A system message along these
lines fixes it:

> You have a tool named `similarity_search` that queries our own knowledge base. Whenever a question
> touches our company, products or processes, call it before answering and base your answer on what it
> returns. Everything it returns is documentation you are authorised to share with this user — never
> refuse on confidentiality grounds. Only say you do not have the information if the search returns
> nothing relevant.

Note that the system message is stored **with the chat session** when the session starts, so an existing
session keeps the old wording — clear the `easychat_session_id` cookie when testing changes.

## Access restricted content is never indexed

Retrieval applies no per-user filter — everything in the vector store is answerable to every chat user,
including anonymous ones. EasyChat therefore refuses to embed anything an anonymous visitor could not see:
`IndexEventListener` drops a page event unless its access groups are empty or explicitly contain `-1`
("hide at login"), which mirrors what TYPO3's `FrontendGroupRestriction` admits for a visitor with no
login.

Two consequences worth knowing:

* **Login-only content cannot be chat knowledge.** Pages behind an `fe_group` are skipped, by design. If
  you need a bot over restricted content, give it its own EasyChat configuration and its own collection,
  and put access control in front of the chat itself.
* **With *Cache* technology, only guest requests contribute.** That queue reports the group ids of the
  visitor whose request filled the cache, and a logged-in visitor's ids never contain `-1`. Pages first
  cached for a logged-in member are skipped and picked up later when a guest requests them.

**Only pages a visitor can open are embedded.** Before a page is embedded, `AnonymousPageVisibility`
checks it the way the frontend does for a visitor without a login, whichever process runs the indexing
(backend, scheduler, `index:queue`, queue worker). It skips a page if the page itself is hidden, outside
its start/stop time or access restricted, or if a page above it restricts or hides its subpages
(*Extend to subpages*). Where *Remove unpublished content from the vector store* is enabled, a page skipped
this way is also removed, so hiding a page and saving it takes it out of the chatbot. Pages of the Frontend
technology are also rendered as an anonymous visitor (`GuestFrontendContextBuilder`), so hidden and
scheduled content elements stay out of them.

File events carry no access information at all (`IndexFileEvent` has no access groups), so files are
embedded purely on the basis of the *File mounts* you configure — keep restricted documents out of those
mounts.

## Keeping the knowledge base in sync

**Changed content is updated in place.** Every chunk has a deterministic id derived from *what* is indexed —
site, language, page, the route arguments of a record (e.g. which news article) and the content element's
`#c<uid>` — not from the URL. Re-indexing an edited content element therefore overwrites its vectors rather
than adding new ones, and that still holds after its page's slug changed. If the element got shorter and
now splits into fewer chunks, the chunks left over from the longer version are deleted right away.

**Removed content is only deleted with *Sync removals*.** A content element or page that is deleted,
hidden, expired, put behind an `fe_group`, or flagged *no_search* is simply no longer emitted by EXT:index,
so by default its vectors stay answerable. Enable *Remove unpublished content from the vector store*
(`vector_db_sync_removals`) on the EasyChat configuration to delete, at the end of every index run,
everything of that index configuration the run did not write again:

* after a **full** run (`index:queue`), across the whole index configuration, files included;
* after a **partial** run, only on the pages — in the languages — that run re-indexed, plus the files if
  the run re-indexed files;
* a page flagged *no_search* (with *Skip no_search pages* on the index configuration) is removed as soon
  as it is saved.

Which vector store point belongs to which document, index configuration, index run, page and language is
tracked in the table `tx_easychat_index_point`. Deleting then only needs a delete-by-id, which every vector
store supports, so none of this is tied to Qdrant (see
[Symfony-AI-Upgrade-Notes.md](Symfony-AI-Upgrade-Notes.md) for how that
becomes plain `StoreInterface::remove()` after upgrading symfony/ai). Two index configurations that index
the same file (overlapping file mounts) each keep their own claim on it; the file only leaves the store
once neither indexes it any more.

How this plays out per EXT:index technology:

| Technology | Updates in place | Removals (with *Sync removals*) |
|---|---|---|
| **Database** | on `index:queue`, and on save with *Partial indexing* | full run: everything no longer emitted · on save: removed content elements of that page |
| **Frontend** / **Http** | same, one document per page URL (no per-element split) | same as Database |
| **Cache** | whenever a guest's request fills the page cache — one document per page and language | only what is re-cached: the page itself and the configuration's files. Pages that are deleted or hidden are never cached again and stay until a purge. All variants of a page (e.g. news detail views) share one document, because this technology reports no URL — prefer *Database* for record-heavy pages |
| **External** (webhooks) | — | — (see below) |

**External content is not supported.** EXT:index's *index external page/file* reactions deliver content
without an index configuration (`-1`), and EasyChat assigns content to a chatbot by index configuration,
so it never reaches a vector store. EXT:index also has no way to announce that an external document is gone.

Safeguards and caveats:

* A run in which writing to the store failed (e.g. the embeddings API was down) or that wrote nothing at
  all is not swept, so an outage cannot wipe the knowledge base.
* EXT:index skips pages that fail to render or fetch (an HTTP 500 or timeout, a rendering exception)
  without telling anyone, which looks exactly like a deleted page. A full run that would therefore remove
  more than *Maximum share removed per run* (`vector_db_sync_removals_threshold`, default 25%) of the
  configuration's vectors removes nothing and logs a warning instead. Raise the value, or purge and
  re-index, if a large removal is intended.
* The sweep relies on a run's page messages being handled before its finish message — true for the
  synchronous transport and a single `messenger:consume` worker, not for several parallel workers.
* Removing the *last* content element of a page emits nothing for that page on save, so it is only
  cleaned by the next full run.
* Vectors written before this feature, or before ids became independent of the URL, are not in
  `tx_easychat_index_point` and are never deleted. Purge and re-index once (see below) after upgrading.

## Known limitations

* **Purging everything.** To start over, drop the collection, forget its points and re-index:
  `curl -X DELETE -H "api-key: <key>" <qdrant>/collections/<collection>`, then
  `DELETE FROM tx_easychat_index_point WHERE configuration = <EasyChat configuration uid>`, followed by a
  full `index:queue` run. The next `add()` recreates the collection.
* **Record-level documents need *content indexing*.** Content types that emit one document per record rely
  on each content element getting its own queue, which only happens with *content indexing* enabled.

## Extending the indexer for your own records

EXT:index exposes four Symfony DI tags, all auto-configured by implementing the matching interface:

| Tag / interface | Use it to |
|---|---|
| `index.content_type` — `ContentTypeInterface` | Teach *Database* indexing how to render your content element. `addVariants()` is the interesting one: return one item per record and you get one document per record |
| `index.extender` — `ExtenderInterface` | Add extra URLs to a *Frontend*/*Http* crawl, e.g. one detail-view URL per record |
| `index.file_extractor` — `FileExtractionInterface` | Support a file format that is not covered yet |
| `index.content_processor` — `ContentProcessorInterface` | Rewrite the content before it is embedded; becomes a checkbox on every index configuration |

A worked example: this distribution's `jpfaq_index_extender` package adds a `ContentTypeInterface` for the
EXT:jpfaq plugin, so every FAQ question in a chosen storage folder becomes its own vector document with
its own question as the title — instead of the whole FAQ accordion collapsing into a single chunk. It
reads the storage folder and category filter from the plugin's own flexform, which keeps the indexed set
identical to what a visitor actually sees on the page.

Alongside the tags there are PSR-14 events (`StartIndexProcessEvent`, `IndexPageEvent`, `IndexFileEvent`,
`FinishIndexProcessEvent`), all also available as core **webhooks** — so the same crawl can feed EasyChat
and an external search service at the same time.
