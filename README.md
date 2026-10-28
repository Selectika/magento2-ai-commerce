# Selectika AI Commerce for Magento

Composer module `selectika/module-ai-commerce`, Magento module `Selectika_AiCommerce`, version 0.1.0.
The module supplies a protected catalog snapshot and the existing Selectika storefront loader,
with an optional native commerce event bridge. All features are disabled on installation.
Account linking is manual in this version. This source package is not a Marketplace listing.

This public repository is generated from Selectika's authoritative monorepo. Maintain changes in
the monorepo's `plugins/magento` directory; do not edit or release directly from this generated
repository. The distribution pipeline publishes the reviewed module snapshot here.

Description-only customers do **not** need this module. Both `direct` and `extension` modes use
the same Selectika AI review and native Magento REST OAuth writeback. Generated drafts stay in
Selectika until approved under the retailer's publication policy. Publishing writes only the
chosen description attribute and checks it with a fresh native API read. The module neither
computes descriptions nor provides another write endpoint. Selecting extension mode in Selectika
does not install it.

## Installation

Target: Magento Open Source / Adobe Commerce 2.4, with the PHP version supported by that
merchant's Magento release. The Composer range is not a tested Magento/PHP compatibility matrix.
Test installation, DI compilation and theme behavior in the merchant's staging environment first.
This repository does not contain a Magento runtime. Source-only tests cannot establish merchant
compatibility; a separate Magento build check is recorded below.

Install a tagged release indexed on Packagist. The command below requires a registered package
and an indexed release in the `0.1` series. Run from the Magento project:

```sh
composer require selectika/module-ai-commerce:^0.1
bin/magento module:enable Selectika_AiCommerce
bin/magento setup:upgrade
bin/magento setup:di:compile
bin/magento setup:static-content:deploy -f en_US he_IL
bin/magento cache:clean
```

Use the merchant's deployment process and installed locales. Check published versions at
[Packagist](https://packagist.org/packages/selectika/module-ai-commerce) and the
[public release repository](https://github.com/Selectika/magento2-ai-commerce).

For local development before publication, copy this module inside the Magento project at
`packages/selectika/module-ai-commerce` and use a path repository:

```sh
composer config repositories.selectika-ai-commerce '{"type":"path","url":"packages/selectika/module-ai-commerce","options":{"versions":{"selectika/module-ai-commerce":"0.1.0"},"symlink":false}}'
composer require selectika/module-ai-commerce:0.1.0
```

Then run the same module-enable, setup, compilation and cache commands above. This version
override identifies a local development copy; it does not publish a package.

Open **Stores → Configuration → Selectika → AI Commerce**, choose the intended **store view**,
and configure the existing Selectika retailer name and public analytics publisher ID. Enable only
the required capabilities. These public fields never receive consumer secrets or access tokens.
Use separate store-view settings where publishers/catalog language differ.

## Native description integration, with or without the module

In **System → Extensions → Integrations**, manually add an integration named
`Selectika descriptions` (or a merchant-approved distinct name). Leave callback and identity URLs
empty for this manual setup. Supply the merchant's real contact email if required by that version.
Select **Catalog → Inventory → Products**, resource `Magento_Catalog::products`, and authorize it.
This native resource permits product operations; Magento does not provide a description-only
field ACL. Selectika's adapter restricts its payload to the chosen description attribute.
The module does not auto-create, overwrite or activate an existing integration.

Save consumer key, consumer secret, access token and access-token secret through Selectika's
Magento connection editor in the retailer's existing Server settings. Choose the explicit store
code, description attribute (`description` or `short_description`), SKU source and active mode.
For the example feed below choose `sourceObj.parent_sku`; a numeric entity ID is not a SKU.
These OAuth secrets live in Selectika's private credential storage, separately from the feed token.
Do not enable Magento's legacy standalone OAuth bearer option to avoid request signing.

Enable the existing description publication policy only after testing an approved product in the
correct store view. Pending approval remains local; publishing performs native REST read/write/
readback. No webhook receiver, administrator password or Magento PHP deployment is required for
the `direct` option. Magento native OAuth resources do not grant access to this module's feed.

## Catalog export and Selectika feed configuration

Enable catalog export for one store view and set a unique random read-only feed token. Generate
64 hexadecimal characters using `php -r 'echo bin2hex(random_bytes(32)), PHP_EOL;'` in a private
terminal. Set the resulting token through Magento's configuration UI; it is encrypted using the
Magento encryption key and marked sensitive for configuration export. Rotate by replacing the
Magento value and the matching Selectika feed header together. Never place the token in a URL.

Magento cron publishes a snapshot hourly at minute 17. Build the first snapshot explicitly:

```sh
bin/magento selectika:catalog:export --store=default
```

The protected GET endpoint is:

```text
https://shop.example.com/selectika/feed/catalog/store/default/
Authorization: Bearer <read-only-feed-token>
```

HTTPS is mandatory, including correct trusted-proxy HTTPS configuration in Magento. A missing,
wrong or insecure credential returns 401; missing, stale or corrupt snapshots return 503, never a
successful empty feed. Successful responses contain `results`, `next`, `snapshot_id`,
`generated_at`, `total_rows`, `total_pages`, `page`, `store_code` and `module_version`. These
authenticated fields also provide a bounded health check. Responses are private/no-store.

Start with the mapping below, then adapt language and the retailer's existing category/gender
mapping. The current generic reader requires `format: "eJsonEndpoint"`,
`factoryConvertor: "other"` and `sources[0].authentication[0]` equal to the **whole** `Bearer ...`
header value. It follows the returned `next` URLs with that same header. The initial URL must use
the queryless `/store/default/` path: the current reader appends its own `?start=...&end=...`.
These date parameters are ignored because each export is a complete snapshot.

```json
{
  "name": "REPLACE_WITH_RETAILER_NAME",
  "lang": "he",
  "factoryConvertor": "other",
  "minNumImages": 1,
  "sources": [{
    "fullUrl": "https://shop.example.com/selectika/feed/catalog/store/default/",
    "localName": "magento-default.json",
    "useLocal": false,
    "saveFeed": true,
    "format": "eJsonEndpoint",
    "additionalName": "magento-default",
    "mergeType": "eIdMerge",
    "authentication": ["Bearer REPLACE_WITH_READ_ONLY_FEED_TOKEN"]
  }],
  "fields": {
    "id": "parent_sku",
    "model": "parent_identifier",
    "title": "title",
    "description": "description",
    "pageLink": "link",
    "images": ["image_urls"],
    "price": "price",
    "salePrice": "sale_price",
    "category": ["category_path"],
    "brand": "brand",
    "fabric": "material",
    "color": "color",
    "sizes": "size",
    "simpleProductId": "id",
    "variantSku": "sku",
    "variantEntityId": "id",
    "stock": "availability",
    "inStock": {"field": "availability", "map": {"in": "in", "out": "out"}, "byQuantity": false}
  }
}
```

The feed token follows the existing authenticated-feed-source configuration visibility in
Selectika; it is not automatically moved to the private OAuth credential store. Restrict who can
read/edit retailer feed settings. Do not paste either credential type into support tickets.

Identity contract:

| Export field | Meaning | Example feed mapping |
| --- | --- | --- |
| `parent_sku` | Exact native parent SKU, standalone SKU for simple products | `fields.id` → catalog `mId`; description writeback source |
| `parent_identifier` | Parent entity ID, independent of SKU | `fields.model` |
| `sku` | Exact child/standalone SKU | `fields.variantSku` |
| `id` | Child/standalone entity ID | `fields.simpleProductId`, `fields.variantEntityId` |
| `size` | Store-view size label, `One size` when absent | `fields.sizes` |
| `color` | Parent color label, constant across that parent's rows | `fields.color` |
| `variant_color`, `color_id`, `size_id` | Exact child attribute labels/option IDs | Retained source data, no automatic parent-ID rewrite |
| `image_urls` | JSON-encoded array of product image URLs | `fields.images: ["image_urls"]` |
| `price`, `sale_price` | Decimal strings required by the generic converter | `fields.price`, `fields.salePrice` |

Use `eIdMerge` with the example's simple entity-ID variant mapping. Do not add `itemGroupId` to
that mapping: it selects a different synthetic variant format in the existing converter.
A configurable parent spanning several colors remains **one catalog entity**. Parent color must
stay constant because the existing merger groups by color before deduplicating parent IDs.
Every child size/entity/SKU is retained, but the catalog's merged images/price/color are a parent
representation, not per-color cards. Different variant prices and a color-specific widget identity
need an explicit retailer-specific mapping and matching storefront context before activation.

Exports include enabled, storefront-visible simple/configurable/virtual/downloadable products in
the store's website. Configurable children use native salability and store-view attributes.
An unsupported visible type (including bundle/grouped), a configurable without exportable
children, an empty catalog or a row over 512 KiB fails the generation and retains the prior one.
This is deliberate protection against silently replacing a catalog with an incomplete export.
Prices are native base-currency/default-customer-group catalog prices; customer-group pricing,
tax-display conversion, currency switching, staged-content preview and custom inventory plugins
require merchant validation. Category paths require the existing Selectika taxonomy mapping.

Snapshots live outside `pub/`, under `var/selectika/catalog/<storeId>`. An exclusive export lock,
temporary generation directory and atomic pointer rename prevent partial reads. Pages contain at
most 250 rows/about 2 MiB, with SHA-256 and sequential row-count validation. `next` pins the same
immutable generation even if cron publishes a replacement. Current data expires after 24 hours;
pinned generations remain usable for 48 hours and are pruned after successful exports. The reader
still accumulates the full catalog in memory, so validate its memory budget for large catalogs.
No export is computed during an HTTP feed request or checkout. Multi-node Magento deployments
must share this `var/selectika` directory between cron and web nodes with working filesystem locks
and atomic rename; local ephemeral storage on different nodes is insufficient. Cron and PHP-FPM
must share Magento's filesystem group; owned snapshot directories use setgid/group access (2770)
and files use 0660. Preserve inherited group ownership on the parent `var/selectika` path.

Do not replace Crazyline's existing feed automatically. Compare product/variant identities,
categories, images, price and stock counts in a separate pilot before changing a live source.

## Storefront, consent and native events

The Luma/Blank RequireJS adapter reuses `https://scripts.selectika.com/selectika-wc.js` and the
existing pixel. Retailer/domain/language resolution, widgets and placements remain configured in
Selectika's current script settings. Register the store host there; the Magento retailer field
does not override loader host resolution. Set the retailer selector
`script.selectors.productIdInput` to `#selectika-product-sku` for this parent-SKU catalog.
`#selectika-selected-variant-id` and `#selectika-selected-variant-sku` expose the selected child;
`selectika:product-context` carries `{parentSku, variant}`. Unknown selections clear the child.
Hyvä, headless/PWA, custom option components and nonstandard checkout flows need a theme adapter.

The default consent gate waits before loading both the loader and pixel. Wire the merchant CMP to:

```js
window.dispatchEvent(new CustomEvent('selectika:consent', {detail: {analytics: true}}));
// Or, once the Magento adapter exists:
window.selectikaMagento.setConsent(true);
// Revocation:
window.selectikaMagento.setConsent(false);
```

Call after adapter initialization (or set the documented `selectika_analytics_consent=1` consent
cookie from the CMP before initialization). The adapter writes that session cookie with Secure,
SameSite=Lax and Path=/; Magento checks it before buffering private events. Never replay actions
that happened before consent. Revocation blocks further commands and clears queued commands; it
does not erase already sent events or another independently installed analytics runtime. Remove
legacy manual/GTM Selectika embeds during migration so they cannot initialize before this gate.

Native tracking is separately opt-in. Disabling that toggle disables this module's native event
bridge; the common loader can still initialize its configured pixel for widget analytics after
consent. It emits the existing `selectikaTrack('event', name, data)`
contract for `page_view`, `view_item`, `view_cart`, `add_to_cart`, `begin_checkout`, `purchase` with
schema version 1, `source: "storefront"`, `selectika_feature: "commerce"`, stable event IDs and
parent SKU `item_id`. Variant SKUs remain separate. Add-to-cart comes from successful Magento cart
controller completion and option-aware quote matching; private cart/order data is delivered by a
store-scoped Magento customer-data section. No email, address or payment details enter events or
full-page cached HTML. Pixel delivery is asynchronous; Magento performs no remote analytics HTTP.

The purchase event represents successful browser order placement, not confirmed payment or a
refund lifecycle. There is no server-side guaranteed-delivery backstop. Section drain is once-only;
network loss/ad blocking can lose events. Deterministic order IDs and a bounded sessionStorage
cache reduce browser replay duplicates; they do not guarantee cross-device exactly-once delivery.
Disable existing **native** commerce tags before opting in. Widget-attributed events remain
separate source/feature records: reporting must distinguish native funnel facts from widget
attribution instead of adding both `add_to_cart` or `purchase` event counts together.

The package supplies CSP host allowances for Selectika scripts/connections/images. Check the
merchant's CSP report and enforcement mode, including checkout; no blanket unsafe-inline rule is
added. Retailer-specific assets may need their own narrow policy entries.

## Packaging and release versions

The canonical package name is `selectika/module-ai-commerce`; its source repository is
[Selectika/magento2-ai-commerce](https://github.com/Selectika/magento2-ai-commerce). Release
versions come from immutable semantic Git tags such as `v0.1.0` or `v0.2.0`. Source
`composer.json` intentionally omits `version` so Packagist uses the tag rather than a second
manually maintained value. Update the runtime `Model/Config.php::VERSION` with each release.
Never move a published tag to different content. The local path-repository override above is
only for testing copied source; it does not create or publish a release.

Composer archives and GitHub/VCS archives use separate filters: `archive.exclude` and
`.gitattributes` both retain runtime PHP/XML/JS/templates, Composer registration/metadata, this
README and an approved license file if one is present. Tests, example fixtures, Git metadata,
credentials, dependency directories and build artifacts are excluded. Production autoload
classmaps also exclude tests/examples. New runtime directories require an explicit packaging
allowlist update and a package-check run.

For Packagist/GitHub, use the tagged source repository with `composer.json` at its root. A ZIP
intended for an independent Composer `artifact` repository has
different requirements: its build copy needs an explicit version derived from the validated
release tag. Do not add that generated version back to VCS metadata. Package checks establish
archive contents and autoload/registration, not publishing or Magento runtime
compatibility. See [Composer versions](https://getcomposer.org/doc/04-schema.md#version) and
[Adobe package guidance](https://developer.adobe.com/commerce/php/development/package/component).

## Validation and rollout checklist

Verified build: Magento Open Source **2.4.9** (official source commit
`755e34dd689021c5165db9d35ecff74f7dc51527`), **PHP 8.5.8** and **Composer 2.10.2**. A fresh Magento
Composer dependency installation, installation of this module's 33-file package, module enablement
and all nine `setup:di:compile` stages passed. This checks one specific build, not a compatibility
matrix. No database was installed: database setup/schema upgrades, storefront behavior, live feed
requests, tracking delivery and authenticated description writes still need merchant staging tests.

The following development checks run from the Selectika monorepo checkout. Test helpers are
excluded from the installable package; merchants should use their staging checks below.

```sh
bash plugins/magento/tests/check.sh
bash plugins/magento/tests/check-package.sh
```

The real generic-converter contract test also runs from this monorepo using its installed Yarn
dependencies and pinned Node version:

```sh
NODE_OPTIONS=--max-old-space-size=8192 yarn jest --config libs/updater/jest.config.ts \
  --roots "$PWD/plugins/magento/tests" --runInBand \
  --runTestsByPath "$PWD/plugins/magento/tests/feed-consumption.test.ts"
```

That test loads the example mapping into the real `C_FormatConvertor` (`other`), `C_DbItem` and
`C_ModelCollector`; it preserves all three children across two colors plus a standalone product.

Tests cover feed authentication, failed/empty export retention, pagination and manifest
completeness, snapshot integrity/expiry/locking, same-parent cart variant matching, store-isolated
event buffering, consent, runtime replay dedupe and payload allowlisting. PHP stubs test contracts;
they do not replace Magento integration tests. XML checks verify well-formedness, not Magento XSDs.
The offline package check bounds ZIP size and entry count, checks every runtime file's hash and
path, then verifies generated Composer autoloading and module registration with a stub registrar.

Before enabling on a merchant: install and compile against its exact Magento/PHP release; build
two store-view snapshots; follow every authenticated page while publishing another snapshot;
verify bad token/HTTP/stale generation failures; compare complete feed identities and totals.
Test full-page cache across two shoppers without private HTML, consent grant/revoke, two variants
of the same parent, cart reload, checkout/order success reload, store switch and native-vs-widget
analytics. Then validate one explicitly approved description through Selectika's existing review
flow and the target store-view REST readback. Source checks alone do not establish runtime readiness.

Official references: [Magento module registration](https://developer.adobe.com/commerce/php/development/build/component-registration/),
[integration authorization](https://developer.adobe.com/commerce/webapi/get-started/authentication/gs-authentication-oauth/),
[private content](https://developer.adobe.com/commerce/php/development/cache/page/private-content/),
[JavaScript initialization](https://developer.adobe.com/commerce/frontend-core/javascript/init/),
[CSP](https://developer.adobe.com/commerce/php/development/security/content-security-policies/).
