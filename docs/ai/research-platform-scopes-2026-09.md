# Research: scope'y i przeglądy platform (Meta FB/IG + YouTube) — stan na 2026-09-06

> **Status: raport badawczy, NIE dokumentacja zaimplementowanego zachowania.** Zasila
> implementację adapterów R4 (B4 FB, B5 IG, adapter YouTube, B9 metryki) oraz tor
> wniosków właściciela (M1–M7, G1–G6, E1–E4). Metoda: oficjalne dokumentacje Meta for
> Developers i Google for Developers (dostęp 2026-09-06) + źródła branżowe pomocniczo.
> Elementy bez oficjalnego źródła oznaczone jako NIEPOTWIERDZONE — niepewne ≠ potwierdzone.

---

## 1. Meta — Facebook Page + Instagram Business

### Wersja Graph API i polityka wygasania

- **Aktualna wersja: v26.0**, wydana **29 lipca 2026**. Wcześniejsze: v25.0 (18.02.2026,
  sunset 29.07.2028), v24.0 (08.10.2025, sunset 18.02.2028), v23.0 (29.05.2025, sunset
  08.10.2027). Wersja Graph API żyje ~2,5 roku od wydania. **Uwaga: v20.0 wygasa
  24 września 2026.** (Źródło: [Graph API Changelog](https://developers.facebook.com/docs/graph-api/changelog), 2026-09-06)
- **Pułapka wersjonowania**: Marketing API ma osobny, szybszy cykl wygaszania (v23.0
  Marketing API wygasło już 9.06.2026, mimo że Graph API v23.0 żyje do 2027) — dla
  publikacji organicznej liczy się kalendarz Graph API.
  (Źródło: [kitchn.io — Meta Marketing API Q2 2026](https://www.kitchn.io/blog/meta-marketing-api-q2-2026-update))

### Dwie drogi do Instagrama (kluczowa decyzja architektoniczna)

Po wygaszeniu Instagram Basic Display (grudzień 2024) istnieją **dwa** wejścia
([IG Platform Overview](https://developers.facebook.com/docs/instagram-platform/overview/), 2026-09-06):

| | **Instagram API with Facebook Login** | **Instagram API with Instagram Login** |
|---|---|---|
| Host | `graph.facebook.com` | `graph.instagram.com` |
| Wymóg powiązania IG↔Strona FB | **TAK — konto IG professional musi być podpięte do Strony FB** | **NIE** — wystarczy samo konto professional |
| Scope'y | `instagram_basic`, `instagram_content_publish`, `instagram_manage_insights` + `pages_show_list`, `pages_read_engagement` | `instagram_business_basic`, `instagram_business_content_publish`, `instagram_business_manage_insights` (nowe nazwy **obowiązkowe od 27.01.2025**) |
| Token | Facebook User / Page token | Instagram User token |
| Ekstra funkcje | hashtag search, product tagging, partnership ads | brak tych funkcji |
| Publikacja + insights | tak | tak |

Dla Taskio, które i tak publikuje na Stronę FB, wariant Facebook Login daje jeden OAuth
dla obu platform — ale wymusza powiązanie IG↔Strona u każdego klienta (klasyczna
przyczyna nieudanego pierwszego connectu).

### Tabela scope'ów Meta

| Scope | Do czego | Review | Warunki |
|---|---|---|---|
| `pages_show_list` | lista Stron użytkownika, wybór Strony | App Review (screencast) dla Advanced | zależność bazowa |
| `pages_manage_posts` | tworzenie/edycja/kasowanie postów, zdjęć, wideo Strony | App Review | wymaga `pages_read_engagement` + `pages_show_list` |
| `pages_read_engagement` | odczyt treści Strony, metadanych, engagement | App Review | zależność dla publish i insights |
| `read_insights` | Page Insights (metryki Strony/postów) | App Review | wymaga `pages_read_engagement` + `pages_show_list` |
| `instagram_basic` (FB Login) | profil + media konta IG professional | App Review | wymaga `pages_show_list` |
| `instagram_content_publish` (FB Login) | publikacja feed photo/video na IG | App Review | wymaga `instagram_basic`, `pages_read_engagement`, `pages_show_list` |
| `instagram_manage_insights` (FB Login) | insights mediów i konta IG | App Review | j.w. |
| `instagram_business_basic` (IG Login) | profil + media | App Review | baza dla pozostałych `instagram_business_*` |
| `instagram_business_content_publish` (IG Login) | publikacja | App Review | wymaga `instagram_business_basic` |
| `instagram_business_manage_insights` (IG Login) | insights | App Review | j.w. |

(Źródło: [Permissions Reference](https://developers.facebook.com/docs/permissions), 2026-09-06)

**Standard vs Advanced Access**
([Access Levels](https://developers.facebook.com/docs/graph-api/overview/access-levels/), 2026-09-06):

- **Standard Access**: automatyczny, ale działa **tylko dla użytkowników z rolą
  w aplikacji** (lub w Business, który przejął aplikację). Dla publikowania wyłącznie na
  własne konta firmy — **wystarcza, bez App Review i bez weryfikacji biznesowej**. To
  realna droga „dzień 0".
- **Advanced Access** (dowolni użytkownicy): **App Review per uprawnienie** (screencast
  pełnego flow) **+ Business Verification obowiązkowa** (od 1.02.2023) + coroczny
  **Data Use Checkup**. Timeline review wg źródeł branżowych 2–4 tygodnie na zgłoszenie
  ([Outstand docs](https://www.outstand.so/docs/configurations/instagram)) —
  NIEPOTWIERDZONE oficjalnie.

### Limity publikacji IG i wymogi mediów

([Content Publishing](https://developers.facebook.com/docs/instagram-platform/content-publishing/)
+ [IG User Media reference](https://developers.facebook.com/docs/instagram-platform/instagram-graph-api/reference/ig-user/media), 2026-09-06)

- **Limit: 100 postów publikowanych przez API na konto w ruchomym oknie 24h**
  (karuzela = 1 post). Sprawdzanie: `GET /<IG_ID>/content_publishing_limit`. Starsze
  źródła mówią o 25/50 — **oficjalny dokument mówi dziś 100**; błąd przekroczenia to code 9.
- **Container flow**: `POST /<IG_ID>/media` (container) → `POST /<IG_ID>/media_publish`
  (creation_id). **Kontener niewykorzystany przez 24h wygasa.** Status kontenera:
  `GET /<container-id>?fields=status_code` (wideo przetwarza się asynchronicznie —
  publish przed `FINISHED` = błąd).
- **Obrazy: musi być publicznie dostępny URL — Meta robi cURL po `image_url`** („the
  image must be on a public server"). **Tylko JPEG**, max **8 MB**, aspect ratio
  **4:5 – 1.91:1**, szerokość 320–1440 px (auto-skalowanie), sRGB. Dokument nie wymusza
  literalnie HTTPS (zaleca US-ASCII w URL) — ale publiczny HTTPS to bezpieczne założenie
  produkcyjne.
- **Wideo/Reels**: MP4/MOV, moov atom na przodzie, H.264/HEVC, AAC ≤48 kHz, 23–60 FPS,
  ≤25 Mb/s, 3 s – 15 min, ≤300 MB. Pojedyncze wideo do feedu publikuje się jako
  `media_type=REELS` (NIEPOTWIERDZONE w tym fetchu, ale zgodne z dokumentacją od 2023).
  Stories wspierane (`media_type=STORIES`); karuzela ≤10 elementów; brak shopping tagów
  i filtrów przez API.
- Publikacja na **Stronę FB**: `POST /{page-id}/feed` / `/photos` **tokenem Strony**
  (nie user tokenem).

### Tokeny Meta

([Long-Lived Tokens](https://developers.facebook.com/docs/facebook-login/guides/access-tokens/get-long-lived)
+ [Business Login for Instagram](https://developers.facebook.com/docs/instagram-platform/instagram-api-with-instagram-login/business-login), 2026-09-06)

| Token | Życie | Odnowienie |
|---|---|---|
| FB user short-lived | godziny (~1-2h) | wymiana `GET /oauth/access_token` (fb_exchange_token) — **nie da się wymienić wygasłego** |
| FB user long-lived | ~60 dni | ponowna wymiana/ponowny login; SDK odświeża przy aktywności |
| **Page token (z long-lived user tokena)** | **bez daty wygaśnięcia** — unieważniany tylko warunkowo (zmiana hasła, odebranie uprawnień, 90 dni nieaktywności użytkownika w aplikacji, deautoryzacja) | pobrać ponownie z user tokena |
| IG User token (Instagram Login) | short-lived → wymiana na long-lived **60 dni** | `GET graph.instagram.com/refresh_access_token?grant_type=ig_refresh_token` — token musi mieć **≥24h** i być jeszcze ważny |

**Czy odnowienie unieważnia poprzedni token — NIEPOTWIERDZONE.** Dokumentacja mówi
tylko, że wymiana zwraca inną wartość; nie deklaruje unieważnienia starego (w praktyce
stary żyje do naturalnego wygaśnięcia). Nie projektować rotacji w oparciu o założenie
unieważnienia.

---

## 2. YouTube Data API v3 — upload

### Klasyfikacja scope'a i weryfikacja OAuth

- `https://www.googleapis.com/auth/youtube.upload` — **sensitive, NIE restricted**.
  Oficjalna [lista Restricted Scopes](https://support.google.com/cloud/answer/13464325)
  (2026-09-06) obejmuje wyłącznie: Gmail, Drive, Google Fit, Google Chat, Data
  Portability, Photos Ambient, Google Health — **żadnego standardowego scope'a YouTube
  tam nie ma**. Klasyfikację pokazuje też automatycznie Cloud Console przy dodawaniu scope'a.
- Konsekwencja: wymagana **sensitive scope verification** — brand verification (domena
  w Search Console, spójny consent screen, strona główna + privacy policy) + **film
  demonstracyjny OAuth flow** (unlisted na YT); typowo „3–5 dni roboczych" wg Google
  ([Sensitive scope verification](https://developers.google.com/identity/protocols/oauth2/production-readiness/sensitive-scope-verification), 2026-09-06).
  **CASA/security assessment NIE dotyczy** — to wymóg wyłącznie restricted scope'ów
  ([Restricted scope verification](https://developers.google.com/identity/protocols/oauth2/production-readiness/restricted-scope-verification):
  assessment przez asesorów ADA/CASA + recertyfikacja co 12 miesięcy — nie dla YouTube).
- **Unverified app**: ekran ostrzeżenia + **dożywotni cap 100 użytkowników na projekt**
  (nie resetuje się przez nowy client ID)
  ([Unverified apps](https://support.google.com/cloud/answer/7454865)).

### Blokada „private/locked" — NADAL OBOWIĄZUJE

Z oficjalnej dokumentacji [videos.insert](https://developers.google.com/youtube/v3/docs/videos/insert)
(2026-09-06): „All videos uploaded via the `videos.insert` endpoint from unverified API
projects created after 28 July 2020 will be restricted to private viewing mode."
Wyjście: **audyt zgodności z YouTube API Services ToS** (formularz „YouTube API Services
audit and quota extension"). **Uwaga — to są dwa OSOBNE procesy**: weryfikacja OAuth
(Google Trust & Safety, consent screen) i audyt API YouTube (zespół YouTube, zdejmuje
blokadę private i daje kwoty). Zaliczenie jednego nie załatwia drugiego.

### Kwoty — DUŻA ZMIANA 2025/2026

Oficjalny stan ([Getting Started](https://developers.google.com/youtube/v3/getting-started)
+ [Quota and Compliance Audits](https://developers.google.com/youtube/v3/guides/quota_and_compliance_audits),
2026-09-06): domyślna alokacja projektu to **trzy osobne kubełki**:

- **100 wywołań `videos.insert` dziennie** (upload kosztuje **1 jednostkę we własnym kubełku**),
- **100 wywołań `search.list` dziennie**,
- **10 000 jednostek dziennie na wszystkie pozostałe endpointy** (odczyty ~1 jednostka,
  zapisy ~50).

Wg źródeł branżowych przejście było dwustopniowe: **4.12.2025** obniżka kosztu
`videos.insert` z 1600 do ~100 jednostek, a **1.06.2026** wydzielenie osobnych kubełków
([Blotato](https://www.blotato.com/blog/youtube-api-pricing),
[SocialCrawl](https://www.socialcrawl.dev/blog/youtube-data-api-2026)) — daty pośrednie
NIEPOTWIERDZONE u Google, ale **końcowy model kubełkowy jest wprost w oficjalnej
dokumentacji**. Praktycznie: stary hamulec „6 uploadów dziennie" (10 000/1600) już nie
istnieje — domyślnie 100 uploadów/dzień. Większa kwota = formularz audytu. Reset
o północy czasu pacyficznego. Limit pliku: 256 GB.

### Refresh tokeny Google

([OAuth2 docs — expiration](https://developers.google.com/identity/protocols/oauth2#expiration), 2026-09-06)

- Consent screen **Testing** (external): refresh token wygasa po **7 dniach** —
  nieużywalne produkcyjnie.
- **In production**: refresh token bez terminu; unieważniany gdy: użytkownik cofnie
  dostęp, **6 miesięcy nieużywany**, zmiana hasła (tylko scope'y Gmail), przekroczony
  limit **100 żywych refresh tokenów na konto Google na client ID** (najstarszy pada bez
  ostrzeżenia — istotne przy wielokrotnym re-consencie tego samego konta).

---

## 3. Metryki — minimalne scope'y

| Platforma | Metryki | Endpoint | Minimalny scope |
|---|---|---|---|
| FB Page post | media views / viewers (dawn. impressions/reach) | `GET /{post-id}/insights` | `read_insights` + `pages_read_engagement` (+`pages_show_list`) |
| IG media | `views`, `reach`, likes/comments/saves/shares | `GET /{ig-media-id}/insights` | `instagram_manage_insights` (FB Login) lub `instagram_business_manage_insights` (IG Login) |
| YouTube views/likes/comments | `statistics.viewCount/likeCount/commentCount` | `videos.list?part=statistics` (1 jednostka, do 50 ID na call) | **żaden — wystarczy API key** dla publicznych wideo; z OAuth wystarczy `youtube.readonly`. **YouTube Analytics API NIE jest potrzebne** do podstawowych liczników |
| YouTube analityka głęboka (watch time, demografia, przychody) | Reports API | osobny scope `yt-analytics.readonly` (+ `yt-analytics-monetary.readonly` dla przychodów) — [Analytics auth](https://developers.google.com/youtube/analytics/authentication) |

**Krytyczne dla FB/IG**: nazwy metryk są w trakcie wymiany (sekcja 4) — nie hardkodować
`post_impressions*`.

---

## 4. Zmiany polityk 2025–2026 (wpływające na projekt)

1. **IG: nowe scope'y `instagram_business_*` obowiązkowe od 27.01.2025** dla Instagram
   Login (stare nazwy przestały działać w tym flow).
2. **IG: metryka `views` zastąpiła `impressions` i `plays` — od 21.04.2025 we WSZYSTKICH
   wersjach API** żądanie `impressions` dla nowszych mediów zwraca błąd (zmiana z v22.0;
   [Emplifi](https://docs.emplifi.io/platform/latest/home/instagram-insights-metrics-deprecation-april-2025),
   [Metricool](https://help.metricool.com/instagram-replaces-impressions-with-views-what-you-need-to-know-f6n8j)).
3. **FB: masowa wymiana metryk impressions/reach w 2026** — v25.0 changelog deprecjonuje
   ~40 metryk (`post_impressions_unique`, `page_posts_impressions`,
   `total_video_views_*`, story impressions...) z zamiennikami `post_media_view`,
   `post_total_media_view_unique`, `page_media_view`, `STORY_MEDIA_VIEW` itd.;
   deprecacja obejmuje **wszystkie wersje z chwilą wydania v26.0** — a v26.0 wyszło
   29.07.2026, więc **we wrześniu 2026 stare metryki należy uznać za martwe**
   ([v25.0 changelog](https://developers.facebook.com/docs/graph-api/changelog/version25.0/)).
   Dokładny zestaw dostępnych dziś nazw zweryfikować na v26.0 w momencie implementacji.
4. **Meta webhooks: od 31.03.2026 własne CA Mety** — trust store odbiorcy webhooków musi
   zawierać `meta-outbound-api-ca-2025-12.pem`, inaczej TLS handshake pada
   ([kitchn.io](https://www.kitchn.io/blog/meta-marketing-api-q2-2026-update)) — istotne,
   jeśli moduł publikacji będzie subskrybował webhooki (np. statusy).
5. **YouTube: kubełkowy model kwot (grudzień 2025 / czerwiec 2026)** — opisany
   w sekcji 2; unieważnia wszystkie starsze poradniki mówiące „1600 jednostek za upload".
6. **Limit publikacji IG = 100/24h** w oficjalnym dokumencie (starsze źródła: 25, potem
   50) — daty podniesienia nie potwierdzono oficjalnie.

---

## 5. Pułapki wdrożeniowe (co zablokuje pierwszy realny connect/publish)

1. **Meta Standard vs Advanced**: pierwszy connect na kontach własnej firmy działa bez
   żadnego review (Standard Access, rola w aplikacji/Businessie). Ale **jakikolwiek
   użytkownik bez roli = Advanced Access = App Review (screencasty per uprawnienie)
   + Business Verification** — proces tygodniowy; odpalić dzień 0.
2. **IG wymaga publicznego URL obrazu** — Meta cURL-uje `image_url`.
   Localhost/wewnętrzny storage/pre-signed URL, który wygaśnie przed publish, wysypie
   container flow. Tylko JPEG (PNG odrzucany!), 8 MB, ratio 4:5–1.91:1.
3. **Container ≠ publikacja**: dwufazowość + 24h TTL kontenera + asynchroniczne
   przetwarzanie wideo (odpytywanie `status_code` przed `media_publish`). Retry po
   niejasnym wyniku publish grozi podwójnym postem — spójne z doktryną
   `needs_reconcile` w R4.
4. **Publikacja na Stronę FB idzie tokenem Strony**, nie user tokenem; user token
   krótkożyciowy wymienić na long-lived ZANIM wygaśnie, bo wygasłego nie da się
   wymienić. Page token z long-lived user tokena nie wygasa — ale pada przy zmianie
   hasła/deautoryzacji, więc obsługa błędu 190 + re-connect flow jest obowiązkowa.
5. **IG↔Strona FB**: przy Facebook Login konto IG professional musi być podpięte do
   Strony — u klientów to najczęstszy powód „nie widzę konta IG po zalogowaniu" (plus
   brak zaznaczenia checkboxów uprawnień w dialogu OAuth).
6. **Google: tryb Testing = refresh token na 7 dni**. Dopóki consent screen nie jest
   opublikowany, każdy connect YouTube umiera po tygodniu. Publikacja consent screenu
   z sensitive scope → wymaga weryfikacji (brand + wideo demo), inaczej ekran
   „unverified" i dożywotni cap 100 userów na projekt.
7. **YouTube: dwa niezależne procesy weryfikacji** — OAuth verification NIE zdejmuje
   blokady „uploads private/locked"; to robi dopiero **audyt API projektu** (formularz
   audit/quota extension). Projekt bez audytu wgra wideo wyłącznie jako prywatne —
   pierwszy „publiczny" upload się nie uda mimo zielonego OAuth.
8. **Limit 100 refresh tokenów/konto/client ID** u Google: wielokrotne testowe
   re-connecty tego samego konta w końcu ubiją najstarszy token bez ostrzeżenia —
   przechowywać jeden token per (konto, client), nadpisywać przy re-consencie.
9. **Metryki**: nie budować schematu na `post_impressions*`/IG `impressions` — obie
   rodziny właśnie umarły (sekcja 4); mapować na `views`/`media_view` od razu, z warstwą
   tłumaczenia nazw per wersja API.
10. **Przypinać wersję Graph API w URL** (`/v26.0/...`) i planować podbicie co ~rok;
    wywołania bez wersji dostają najstarszą żyjącą, co maskuje deprecje.

## Czego nie udało się potwierdzić

- Czy odnowienie tokena Meta (user long-lived, IG refresh) **unieważnia poprzedni** —
  dokumentacja milczy.
- Oficjalne daty podniesienia limitu IG 25→50→100/24h (potwierdzony jest tylko stan
  obecny: 100).
- Dokładne pośrednie daty zmian kwot YouTube (4.12.2025 / 1.06.2026 — tylko źródła
  branżowe; model docelowy potwierdzony oficjalnie).
- Oficjalny SLA App Review Mety (2–4 tygodnie to dane branżowe).
- Czy retirement „reach" z czerwca 2026 objął też metrykę `reach` w **IG** insights
  (potwierdzony dla metryk Strony/postów FB) — sprawdzić na v26.0 przy implementacji.
- Wymóg literalnie HTTPS dla `image_url` IG — dokument mówi „public server"; HTTPS
  przyjąć jako standard, ale nie jest zacytowanym wymogiem.

## Źródła (dostęp 2026-09-06)

Oficjalne: [Graph API Changelog](https://developers.facebook.com/docs/graph-api/changelog)
· [v25.0 changelog](https://developers.facebook.com/docs/graph-api/changelog/version25.0/)
· [Permissions Reference](https://developers.facebook.com/docs/permissions)
· [Access Levels](https://developers.facebook.com/docs/graph-api/overview/access-levels/)
· [IG Content Publishing](https://developers.facebook.com/docs/instagram-platform/content-publishing/)
· [IG User Media](https://developers.facebook.com/docs/instagram-platform/instagram-graph-api/reference/ig-user/media)
· [IG Platform Overview](https://developers.facebook.com/docs/instagram-platform/overview/)
· [Business Login for Instagram](https://developers.facebook.com/docs/instagram-platform/instagram-api-with-instagram-login/business-login)
· [Long-Lived Tokens](https://developers.facebook.com/docs/facebook-login/guides/access-tokens/get-long-lived)
· [videos.insert](https://developers.google.com/youtube/v3/docs/videos/insert)
· [YT Getting Started (kwoty)](https://developers.google.com/youtube/v3/getting-started)
· [Quota & Compliance Audits](https://developers.google.com/youtube/v3/guides/quota_and_compliance_audits)
· [Sensitive scope verification](https://developers.google.com/identity/protocols/oauth2/production-readiness/sensitive-scope-verification)
· [Restricted scope verification](https://developers.google.com/identity/protocols/oauth2/production-readiness/restricted-scope-verification)
· [Restricted Scopes list](https://support.google.com/cloud/answer/13464325)
· [Unverified apps](https://support.google.com/cloud/answer/7454865)
· [OAuth2 token expiration](https://developers.google.com/identity/protocols/oauth2#expiration)
· [YT Analytics auth](https://developers.google.com/youtube/analytics/authentication).
Branżowe (pomocniczo): [kitchn.io Q2 2026](https://www.kitchn.io/blog/meta-marketing-api-q2-2026-update)
· [Blotato YT pricing](https://www.blotato.com/blog/youtube-api-pricing)
· [SocialCrawl YT 2026](https://www.socialcrawl.dev/blog/youtube-data-api-2026)
· [Emplifi IG deprecation](https://docs.emplifi.io/platform/latest/home/instagram-insights-metrics-deprecation-april-2025)
· [Metricool views](https://help.metricool.com/instagram-replaces-impressions-with-views-what-you-need-to-know-f6n8j)
· [Outstand IG review](https://www.outstand.so/docs/configurations/instagram).
