# Proof Partner

Партньорска (affiliate) система на LOGADOR за proofnutrition.eu: купон при checkout = комисиона (lock), per-partner ставки, правила по оферти (комисиона/отстъпка/подновявания, глобални и лични), фиксиран договор по фактура + ROI, служебни пратки, портал /partner, импорт на купони.

## Нова версия
1. Вдигни версията на **двете** места в `proof-partner.php`: header `Version: X.Y.Z` и `define( 'PROOF_PARTNER_VERSION', 'X.Y.Z' );` (и `Stable tag` в readme.txt).
2. Push в `main`.

GitHub Action-ът прави `php -l`, zip с папка `proof-partner/`, тага `vX.Y.Z` и Release-а. Сайтът се обновява през LOGADOR GitHub Updater (mu-plugin; token в Settings → GitHub ъпдейти) → Plugins → Update now / auto-updates.
