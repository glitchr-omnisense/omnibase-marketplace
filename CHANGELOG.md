# Changelog

## [Unreleased]

### Changed
- `QuoteCrudController` numbers a new quote in `persistRecord()`, omnibase/admin's write hook on the persistence the site
  chose, in place of the former `persistEntity(EntityManagerInterface)` (deprecated since the admin's step B1a); its
  repository is given by `setQuotes()`. Needs omnibase/admin from its step B1a.
- `OrderCrudController`'s "cart" filter says its condition in the core's finder language (`Filter::matchWith()`: the
  state LIKE, or NOT LIKE, 'ORDER_CART%'), which every persistence reads; `applyWith()` keeps the same condition for a
  list still built on Doctrine's QueryBuilder (omnisense/omnibase 4.x). tests/Controller/OrderCartFilterTest.

### Changed
- Accepts omnisense/omnibase 5.0 (`3.0.*-dev || 4.0.*-dev || 5.0.*-dev`; plan "omnibase agnostic", step S): the bundle runs
  unchanged on it, `Base\Bundle\AbstractBaseBundle` and the other former names it uses being aliases of
  omnibridge/symfony's classes there. The sites on 4.x resolve as before.
- `Product` and `Review::__autocomplete()` render through omnibridge's `Views` (`Runtime::views()`: omnibridge/twig's
  `TwigViews` under Symfony, omnisense/omnibase 4.x step F5a) where the runtime answers it, BaseTrait's deprecated
  `getTwig()` otherwise (the core 3.x): the same template, the same HTML.
- `Brand`, `Order`, `Product`, `Review` and `Store::__toLink()` link through `Base\Contract\Link::to()` (the Urls contract; the same address under
  Symfony, in the core 3.x and 4.x) instead of BaseTrait's `getRouter()->generate()`, which the
  core deprecates since BaseTrait names contracts only (omnisense/omnibase 4.x, step F2f).

### Fixed
- README: the package's name, omnibase/marketplace (the title was base-bundle-marketplace), and
  the omnisense/* families it works with (omnisense/omnitrade, omnisense/omnibus,
  omnisense/omnishield, omnisense/omnistate) in place of their former glitchr/* names and the
  abandoned gitlab.glitchr.dev links; the demo image named omnibase-marketplace-demo.
