# Changelog

## [Unreleased]

### Changed
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
