# Google Search Console checklist - tablet, screen by screen (2026-10-28)

Use Chrome on the tablet, signed in with the Google account that owns the
business. Site: https://centralexecutivetransfers.co.uk. This is all done inside
Google's tools. Nothing here changes the website.

## 1. Check or verify the property
1. Go to search.google.com/search-console.
2. Tap the property drop-down (top left). If `centralexecutivetransfers.co.uk` or
   `https://centralexecutivetransfers.co.uk/` is listed, pick it and skip to step 2.
3. If not: Add property > "URL prefix" > enter `https://centralexecutivetransfers.co.uk/` > Continue.
4. Choose the easiest method offered:
   - Google Analytics or Google Tag Manager (if already on the site - quickest), or
   - HTML tag (give the tag to whoever can add it: needs a site edit, so the
     director uploads it by hand with a backup), or
   - Domain name provider (GoDaddy) via DNS TXT record.
5. Tap Verify. Success shows "Ownership verified".
6. Settings (cog, bottom left) > Users and permissions: add the other director as Owner so
   it does not depend on one account.

## 2. Submit the sitemap
1. Left menu > Indexing > Sitemaps.
2. In "Add a new sitemap" type `sitemap.xml` > Submit.
3. Status should read "Success". Tap it to see "Discovered pages". It should
   be about 25 SEO pages plus the main pages (more once the Manchester page is added).
4. If it says "Couldn't fetch": open https://centralexecutivetransfers.co.uk/sitemap.xml in a
   browser tab. If that loads, wait a day and retry. If not, tell the developer.

## 3. Request indexing, in this order (money pages first)
Method for each: tap the search bar at the top > paste the full address > Enter >
wait for the result > tap "Request indexing" > wait (about a minute) for
"Indexing requested". Google caps this to about 10 a day, so do the list over several days.
If the page already says "URL is on Google", you can still request indexing after
any edit, but not otherwise.

Day 1:
1. `https://centralexecutivetransfers.co.uk/` (homepage)
2. `https://centralexecutivetransfers.co.uk/booking`
3. `https://centralexecutivetransfers.co.uk/airport-transfers-sheffield`
4. `https://centralexecutivetransfers.co.uk/sheffield-to-manchester-airport` (NEW, only after it is live)
5. https://centralexecutivetransfers.co.uk/sheffield-to-manchester-airport-transfers
6. https://centralexecutivetransfers.co.uk/sheffield-to-east-midlands-airport-transfers
7. https://centralexecutivetransfers.co.uk/sheffield-to-heathrow-airport-transfers

Day 2:
8. Leeds Bradford, Gatwick, Stansted, Luton, Liverpool, Doncaster Sheffield/Humberside airport pages
9. Corporate / business travel page (if one exists)
10. Town pages (Rotherham, Barnsley, Doncaster, Chesterfield) as they appear in the sitemap

Day 3:
11. Anything left in the sitemap, then the legal pages last.

Tip: open sitemap.xml in a browser tab, long-press each address > Copy, then paste into
Search Console. That way no address is mistyped.

## 4. Fix coverage problems
1. Left menu > Indexing > Pages.
2. Read the two numbers: Indexed and Not indexed. Scroll to "Why pages aren't indexed".
3. What each reason means:
   - "Discovered - currently not indexed" / "Crawled - currently not indexed": Google
     has seen the page but is not convinced. Improve the page (more local detail, links
     from other pages) and request indexing again.
   - "Page with redirect": usually fine (http to https, www to non-www).
   - "Not found (404)": a link or sitemap line points at a page that does not exist.
     Write down the address and send it to the developer.
   - "Excluded by noindex": serious if it is a money page. Report immediately.
   - "Duplicate, Google chose different canonical": two pages say the same thing. Report.
4. Tap a reason > tap an address > "Inspect URL" for details.

## 5. Weekly check (10 minutes, Monday)
1. Performance > Search results > date: Last 28 days. Note Clicks, Impressions, CTR, Position.
2. Queries tab, then sort by Impressions:
   - High impressions, low CTR (under about 2%) = rewrite that page's title/description.
     Send the query and page to the SEO agent.
   - Average position 5 to 15 = nearly there. Improve that page (more content, FAQs,
     internal links).
3. Pages tab: which pages get clicks? Is the new Manchester page showing yet?
4. Indexing > Pages: has "Not indexed" grown? Any new reason?
5. Experience > Core Web Vitals and Mobile usability: any new "Poor" items?
6. Export: tap Export (top right) > Download CSV for Queries and Pages, and save them in
   `docs/growth/data/` (not committed to the repo, not shared) for the SEO agent.
7. Check the Sitemaps page still says "Success".
8. Links report: note any new sites linking to us.

## Do these 3 first:
1. Verify the property and add the second director as Owner.
2. Submit `sitemap.xml`.
3. Request indexing for the homepage, /booking and /airport-transfers-sheffield, then the new Manchester page once live.


## Full list of CET page addresses (from the uploaded SEO batch)
Airports: /airport-transfers-sheffield · /airport-transfer-company-sheffield · /sheffield-to-manchester-airport-transfers · /sheffield-to-east-midlands-airport-transfers · /sheffield-to-heathrow-airport-transfers · /sheffield-to-gatwick-airport-transfers · /sheffield-to-stansted-airport-transfers · /sheffield-to-luton-airport-transfers · /sheffield-to-birmingham-airport-transfers · /sheffield-to-leeds-bradford-airport-transfers · /sheffield-to-liverpool-airport-transfers · /sheffield-to-newcastle-airport-transfers
Towns: /rotherham-airport-transfers · /barnsley-airport-transfers · /doncaster-airport-transfers · /chesterfield-airport-transfers
Services: /executive-chauffeur-sheffield · /corporate-chauffeur-sheffield · /corporate-travel-sheffield · /vip-chauffeur-sheffield · /event-chauffeur-sheffield · /long-distance-chauffeur-sheffield · /mercedes-vclass-hire-sheffield · /minibus-hire-sheffield · /prom-wedding-chauffeur-sheffield · /open-account
Legal (index last): /terms-and-conditions · /privacy-policy · /cancellation-policy
(Check each opens on the live site before requesting indexing — /airport-transfer-company-sheffield needed its route added.)
