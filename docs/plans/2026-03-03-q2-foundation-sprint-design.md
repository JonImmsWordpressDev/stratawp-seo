# Q2 Foundation Sprint — Design Document

**Date:** 2026-03-03
**Scope:** Content Scoring & Voice Profiles, In-Content Image Insertion, Developer Hooks Expansion
**Approach:** Hook-based modular services — new classes plug into the existing pipeline via WordPress filters/actions without heavy modification to SWPS_Generator.

---

## 1. Content Scoring

**New class:** `SWPS_Content_Scorer` in `includes/class-content-scorer.php`

Seven analyzers, each returning a score 0–100. Weighted average produces overall score.

| Analyzer | Weight | Method |
|----------|--------|--------|
| Keyword Density | 15% | Primary/secondary keyword frequency vs content length. Flags stuffing (>3%) or underuse (<0.5%) |
| Readability | 20% | PHP Flesch-Kincaid calculation — sentence count, syllable count, avg sentence length |
| Heading Structure | 15% | H2/H3 hierarchy, keywords in headings, no skipped levels |
| Meta Quality | 15% | Title 50-60 chars, meta desc 150-160 chars, keyword in both |
| Internal Links | 10% | Link count vs word count ratio, non-generic anchor text |
| Content Depth | 15% | Word count vs target, paragraph count, heading count |
| Engagement | 10% | Questions, lists, CTAs, media references |

**Integration:**
- Hooks into `swps_post_created` action
- Stores `_swps_content_score` (int) and `_swps_score_details` (array) as post meta
- Optional gate: if `swps_min_content_score` is set and score is below, post status forced to `draft` with `_swps_score_blocked` flag
- All analyzers are pure PHP — no AI calls

**New option:** `swps_min_content_score` (int, default 0 = disabled)

**Admin UI:** Score badge in post list column for SWPS-generated posts. Score displayed in generation results panel.

---

## 2. Voice Profiles

**New class:** `SWPS_Voice_Profile` in `includes/class-voice-profile.php`

**Storage:** Custom post type `swps_voice_profile` with post meta:

| Meta Key | Type | Purpose |
|----------|------|---------|
| `_swps_vp_tone` | string | professional, casual, authoritative, friendly |
| `_swps_vp_formality` | int 1-10 | Formality scale |
| `_swps_vp_sentence_length` | string | short, medium, long, varied |
| `_swps_vp_vocabulary_level` | string | simple, moderate, advanced, technical |
| `_swps_vp_person` | string | first, second, third |
| `_swps_vp_example_content` | longtext | Sample paragraphs for style matching |
| `_swps_vp_avoid_phrases` | JSON array | Phrases to never use |
| `_swps_vp_preferred_phrases` | JSON array | Preferred expressions |

**Prompt compilation:** `compile()` method converts fields to natural language:
> "Write in a professional tone at formality level 7/10. Use varied sentence lengths. Vocabulary: moderate. Use second person. Never use: 'game-changer', 'leverage'. Prefer: 'practical', 'straightforward'. Match this writing style: [first 500 chars of example_content]"

**Integration:**
- Hooks into `swps_system_prompt` filter
- New option `swps_voice_profile` selects active profile (0 = none, falls back to tone/style options)
- Filter `swps_voice_compile` allows modification of compiled prompt

**Admin UI:**
- Submenu page "Voice Profiles" under StrataWP SEO
- List table with name, tone, formality columns
- Add/Edit form for all fields
- Dropdown on Settings page to select active profile

---

## 3. In-Content Image Insertion

**New class:** `SWPS_Image_Inserter` in `includes/class-image-inserter.php`

**Pipeline:**
1. Parse `content_html` into sections (split on `<h2>`)
2. Extract 2-4 word visual concepts per section (PHP string analysis — strip stop words, pick nouns/adjectives from heading + first paragraph)
3. Search configured image provider for each concept (reuses existing provider infrastructure)
4. Download, WebP convert, upload to media library (reuses `download_and_attach_image()`)
5. Insert `<figure><img loading="lazy" width="..." height="..."><figcaption>...</figcaption></figure>` after first paragraph of target section
6. Update post content via `wp_update_post()`

**Configuration:**

| Option | Default | Purpose |
|--------|---------|---------|
| `swps_insert_content_images` | `0` | Enable in-content images |
| `swps_content_images_count` | `2` | Max images (1-4) |
| `swps_image_max_width` | `1200` | Max width px |

**Integration:**
- Hooks into `swps_post_created` action, runs after featured image
- Skips intro section (featured image covers it)
- Filter `swps_image_selection` before each insertion
- Filter `swps_content_images_queries` after concept extraction, before search
- Action `swps_image_inserted` after each image placed

**Image attributes:** `loading="lazy"`, contextual alt text, explicit `width`/`height` for CLS.

---

## 4. Developer Hooks Expansion

All existing hooks remain unchanged. New hooks added by the three features above.

**New Filters:**

| Filter | Parameters | Fires when |
|--------|-----------|-----------|
| `swps_score_weights` | `$weights, $post_type` | Before scoring pipeline |
| `swps_voice_compile` | `$compiled_prompt, $profile_id` | Voice profile compilation |
| `swps_image_selection` | `$image_data, $post_id, $section_heading` | Before in-content image insert |
| `swps_content_images_queries` | `$queries, $post_id` | After concept extraction |

**New Actions:**

| Action | Parameters | Fires when |
|--------|-----------|-----------|
| `swps_score_complete` | `$results, $post_id` | Scoring pipeline finished |
| `swps_image_inserted` | `$attachment_id, $post_id, $alt_text, $position` | In-content image placed |

**New REST Endpoints** (namespace `swps/v1`):

| Method | Endpoint | Purpose |
|--------|----------|---------|
| `POST` | `/score/{id}` | Score existing post |
| `GET` | `/score/{id}` | Get stored score |
| `GET` | `/voice-profiles` | List profiles |
| `POST` | `/voice-profiles` | Create profile |
| `PUT` | `/voice-profiles/{id}` | Update profile |
| `DELETE` | `/voice-profiles/{id}` | Delete profile |

All require `manage_options` capability.

---

## Files to Create

| File | Class |
|------|-------|
| `includes/class-content-scorer.php` | `SWPS_Content_Scorer` |
| `includes/class-voice-profile.php` | `SWPS_Voice_Profile` |
| `includes/class-image-inserter.php` | `SWPS_Image_Inserter` |
| `templates/voice-profiles-page.php` | Voice profile admin UI |
| `templates/voice-profile-edit.php` | Voice profile add/edit form |

## Files to Modify

| File | Changes |
|------|---------|
| `stratawp-seo.php` | Require new classes, instantiate in constructor, register new admin menu/AJAX |
| `includes/class-settings.php` | Add voice profile dropdown, content images toggle, min score option |
| `includes/class-rest-api.php` | Add score and voice-profile endpoints |
| `includes/class-hooks.php` | Add new filter/action helper methods |
| `admin/css/admin.css` | Score badge styles, voice profile form styles |
| `admin/js/admin.js` | Score display, voice profile CRUD UI interactions |
| `templates/settings-page.php` | New settings fields for images and scoring |
