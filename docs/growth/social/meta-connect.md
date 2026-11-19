# Connect Facebook and Instagram to the Social Media Command Centre

About 15 minutes, once. Free. **Read-only**: the Command Centre only reads numbers
(followers, views, likes, comments, shares, saves). It never posts, edits or deletes.

Do this on a computer, logged in to Facebook as an admin of the CET Facebook Page.
Meta moves its buttons around now and then. If a name below doesn't match, look for
the nearest match or send Claude a screenshot.

## 1. Instagram must be a business account linked to the Page

1. Instagram app → your profile → ☰ → **Settings** → **Account type and tools** →
   **Switch to professional account** → **Business** (skip if already done).
2. Link it to the CET Facebook Page: in the Facebook Page → **Settings** →
   **Linked accounts** → **Instagram** → Connect. (Or through **Accounts Centre**.)

## 2. Create a free Meta developer app

1. Go to **developers.facebook.com** → log in → **My Apps** → **Create app**.
2. Choose **Other** → **Business**. Name it `CET Command Centre`. Create.
3. Leave it in **Development** mode. As its admin you can use it on your own Page
   without Meta reviewing it.

## 3. Get the Page ID and a Page access token

1. **Tools** → **Graph API Explorer**. Pick the `CET Command Centre` app top right.
2. **User or Page** → **Get User Access Token**. Tick these permissions:
   `pages_show_list`, `pages_read_engagement`, `read_insights`,
   `instagram_basic`, `instagram_manage_insights`, `business_management`.
3. **Generate Access Token** → approve → choose the CET Page and the CET Instagram account.
4. Make it long-lived: click the ⓘ next to the token → **Open in Access Token Tool** →
   **Extend Access Token** → copy the new (long) token back into the Explorer box.
5. In the Explorer request box type `me/accounts` and press **Submit**.
   Find Central Executive Transfers in the result and copy:
   - `id` → the **Page ID** (numbers only)
   - `access_token` → the **Page access token** (long text). A Page token made from
     a long-lived user token doesn't expire unless you change your password or remove the app.

## 4. Paste them into the Command Centre

Command Centre → **Social media** → **Overview** → bottom card **Facebook & Instagram
numbers** → **Connect** (super-admin only) → paste the Page ID and token →
**Save and test**. It should say "Connected to Central Executive Transfers and Instagram @…".

Then tap **Sync now**. After that it updates by itself every night at about 3:20am.

## Keep it safe

- Paste the token only into the Command Centre. Don't send it in chat, email or WhatsApp.
- The token isn't shown again on screen once saved.
- To cut the connection at any time: Facebook → **Settings** → **Business integrations**
  → remove `CET Command Centre`. The Command Centre then simply stops updating.

## What it fills in

- Follower counts for Facebook and Instagram (weekly log, shared with the Growth dashboard).
- Each post from the last 60 days: views (reach where Meta doesn't give views), likes,
  comments, shares, saves.
- Planned posts that went out are matched and marked **Posted** with their link.
- Posts made straight in the app are added to the Posts list too.

TikTok, LinkedIn and Google Business numbers are still typed in by hand (their APIs
need approval first).
