# Inspection Form Entry Validation

**Updated:** 26-11-2026

This document lists every entry in the inspection form and how it is checked. Each entry is checked in up to three places:

- **Screen:** the browser checks it while the officer types. Code: `resources/js/wizard.js`, `camera.js`, `review.js` and `offline-queue.js`.
- **Server:** the server checks it when it is saved. Code: `InspectionController`, `EvidenceController` and `InspectionValidator`.
- **Database:** the database refuses rows that break a rule (MySQL CHECK constraints). The rules are added by migration `2026_11_26_000007_add_inspection_check_constraints`.

**The rule for every message:** it says what is wrong **and what to do**. A wrong value is refused. It is never changed or cut without telling the officer: "1.9" is not stored as 1, and long text is not cut short.

## How messages are shown

| Where | What the officer sees |
|---|---|
| Question answer | Red text under the answer, and a red badge and label. The save pill shows **"Not saved: fix the red box"**. Screen readers are told the field is wrong (`aria-invalid`) and which message belongs to it (`aria-describedby`). |
| Remarks of an answer | Red text under the **remarks box**. A mandatory remark left empty is shown at once, when the officer leaves the box. |
| Absent teacher / classroom / fund forms | Red border and a message under each field. The first wrong field is scrolled to and focused. |
| Suggestions | A message under the suggestion box, naming it: "Suggestion 2 is too long…". |
| Photos / documents | Messages pop up (toast) before anything is sent. A file the server refused shows **Not accepted** with the reason and what to do, and no Retry button, because it cannot be sent again. |
| Leaving a step (Next) | A list of what is missing, with any answers **not saved** listed first, in red. The officer can still continue, as before. |
| Changes made without network that the server refused | A red box above the form, and on the Review page. It names each change (e.g. *Absent teacher "Rina Das"*), gives the reason, and has an **I have entered them again** button. An answer on the current step is also marked red. |
| Submit | The Review page lists everything still missing, each with a **Fix** link. Submit stays locked until the list is empty. |

## Step 1 to 6: question answers (`saveAnswer`)

| Entry | Screen | Server | Message (what to do) |
|---|---|---|---|
| Yes / No / option | Saved on change | Must be one of the options | "Choose one of the options shown." |
| Tick boxes (multi) | Saved on change | Only the options shown | "Tick only the options shown." |
| Number (counts) | The keys `e`, `+`, `-`, `.` and `,` are blocked. Letters are found (`badInput`). The − / + buttons stay between 0 and 1,00,000 | Whole digits only, from 0 to 1,00,000 | "Numbers only, e.g. 12. Remove letters, spaces or signs." / "Enter a whole number (0, 1, 2 …) without decimals or a minus sign." / "This number looks too large (more than 1,00,000). Please check it." |
| Date | From 2000-01-01 up to today | A real date, not in the future, year 2000 or later | "Enter a valid date (DD-MM-YYYY), not in the future." |
| Short text | 2 to 200 characters | The same. Not cut short | "Please write at least 2 characters." / "Too long: at most 200 characters (you typed N). Shorten the answer." |
| Reason box (textarea) | 2 to 2000 characters | The same | The same messages, with 2000. |
| Remarks | Up to 2000 characters. A mandatory remark is checked when the officer leaves the box | Up to 2000 characters | "Please write the remarks for this answer (what is the problem?)." / "Too long: at most 2000 characters (you typed N). Shorten the remarks." |
| A question no longer asked (hidden codes such as TSI_14 / TSI_15, BAS_*, ABS_00) | Not shown | Refused. This stops anyone getting around the teacher-totals rule | "This question is not part of the form any more. Reload the page and answer the questions shown." |
| No internet at the school (NET_01 = Yes, 28-11-2026) | NET_01 is the first question of step 1. On Yes, the last step ends with a **Declaration** section: the tick (NET_02) and a **Signed declaration (PDF)** box with **Download declaration form** (all the details entered, the declaration and the signature boxes). The picker takes PDF only, at most 5 MB | NET_02 is required. The signed PDF (document with `question_code` NET_02) must be a PDF, and is required at submit. A document on a question without a `document` rule is refused | "Attach the signed declaration as a PDF file. Scan the signed paper as PDF, then attach it again." / Review: "Attach the declaration signed by the Block / District officer (PDF)" |
| A value in the wrong form (e.g. a list sent instead of one value) | — | Refused with the 422 below (was a server error) | "This answer could not be read. Choose or type it again." |
| Follow-up questions (`show_if` / `enable_if`) | Cleared on screen when hidden | Cleared when the parent answer changes. A save to a hidden question is stored as empty | — |

### Step 2: teacher numbers (checked at submit)
| Rule | Message |
|---|---|
| Teachers present ≤ Total teachers | "Teachers present (X) cannot be more than Total teachers (Y)." |
| Trained + Untrained = Total teachers | "Trained (a) + Untrained (b) = s, which is more / less than Total teachers (t). Trained + Untrained must be equal to Total teachers." |
| Total = Present removes the absent list | Toast: "All teachers present: the absentee list (N rows) was removed." |

## Step 3: absent teachers

| Entry | Screen | Server | Database | Message |
|---|---|---|---|---|
| Teacher's name | Required, 2 to 120 characters, letters only | The same (`NAME_PATTERN`: letters, spaces, `. ' ( ) -`). Extra spaces are removed | At least 2 characters | "Please enter the teacher's name." / "Use letters for the teacher's name (spaces and . ' - are allowed)." |
| Teacher's type | Required | One of the listed types. **New: no more absent teachers of a type than step 2 has of that type.** Regular = TSI_04 + TSI_06 (Regular + SSA). Contractual = TSI_05. Tutor = TSI_07 + TSI_08 (`InspectionAbsentTeacher::TYPE_COUNT_CODES`) | Only the listed values | "Step 2 has no Tutor (0). Choose another type for this teacher, or enter the number of Tutors on step 2." / "Step 2 has 1 Contractual Teacher(s), but 2 are marked absent. Change the type of the extra row(s), or correct the number on step 2." |
| Reason | Required | One of the 7 codes | Only those codes | "Please select the reason for absence." |
| Remarks | Up to 500 characters | Up to 500 characters | — | "The remarks is too long: at most 500 characters. Shorten it." |
| Number of rows | Add is disabled when the list is full | Rows = Total − Present | — | "Only N teacher(s) can be absent (Total teachers − Present on step 2). All N are already added." |
| At submit | — | Exact count, and the type counts again (in case step 2 was changed later) | — | "Add the details of all N absent teacher(s) (M added)" and the type message above. |

## Step 4: classroom observations

| Entry | Screen | Server | Database | Message |
|---|---|---|---|---|
| Name of the teacher | Required, letters only. **New: cannot be a teacher marked absent today.** The name suggestions leave out absent teachers (`?present=1`) | The same. Case and extra spaces are ignored | At least 2 characters | "Rina Das is marked absent today on step 3. Choose a teacher who is present, or remove them from the absent list." |
| Class | Required | I to XII | I to XII only | "Please select the class." |
| Subject | Required, 2 to 100 characters | The same (the minimum of 2 is new on the server) | At least 2 characters | "The subject is too short: write at least 2 characters." |
| Use of TLM | Required | Yes / No | — | "Please choose Yes or No for use of TLM." (was "Please choose yes.") |
| TLM items | At least one tick when Yes | Active items, or items the row already has | Pairs are unique | "Tick at least one TLM item used." / "A ticked TLM item is no longer in the TLM list." |
| Participation | Required | One of the two values | Only those values | "Please choose the participation of students." (was "Please choose satisfactory.") |
| At submit | — | At least one record. No absent teacher among the observed teachers | — | "Add at least one classroom performance record" / the absent-name message above. |

## Step 5: fund details (in lakh, optional)

| Entry | Screen | Server | Database | Message |
|---|---|---|---|---|
| Financial year | Required | From the list | Format `FY-2025-26` | "Select the financial year first." |
| Each amount | Shown with 2 decimals when the officer leaves the box. Letters are found. The keys `e`, `+`, `-` are blocked | A number, up to 2 decimals, from 0 to 99,999 | From 0 to 99,999 | "Numbers only, e.g. 1.25 (amount in lakh). Remove letters, commas or spaces." / "The amount cannot be negative. Enter 0 or more." / "Use a number with up to 2 decimals, e.g. 1.25 or .2 (= 0.20)" / "Too large. Enter the amount in lakh (e.g. 2.50 for 2,50,000)." |
| At least one amount | Yes | Yes | — | "Enter at least one amount for FY 2025-26." |
| Expenditure more than received | Warning only (a decision: it does not block) | — | — | — |

## Step 6: suggestions
| Entry | Screen | Server | Message |
|---|---|---|---|
| Up to 3 suggestions | 2 to 500 characters each, or left empty | The same | "Suggestion 2 is too long: at most 500 characters. Shorten it." / "Suggestion 1 is too short: write at least 2 characters, or clear the box." |

## Step 7: photos and documents (`EvidenceController`)

| Entry | Screen | Server | Database | Message |
|---|---|---|---|---|
| Photo type | Chosen in the camera | Required for a photo, unless the photo belongs to a question | — | "Choose what the photo shows (the photo type), then save it again." |
| Location | "Getting location…"; **Use photo** waits for it | Latitude −90 to 90, longitude −180 to 180, accuracy 0 or more | The same ranges | — |
| No location: reason | Required, with an inline message (camera). The gallery asks in a small window, no longer the browser prompt | Required when there is no location, up to 255 characters | — | "The location could not be found. Write why (e.g. "No GPS signal inside the building") to use this photo." |
| Photo time | Set by the phone | Not in the future (10 minutes are allowed for a clock that runs fast) | — | "The photo time is in the future. Check the phone's date and time, then take the photo again." |
| Number of photos / documents | Checked before sending (photos 20, documents 5) | Checked again with the inspection locked, so two uploads at once cannot pass the limit | — | "You already added 5 documents, the most allowed. Remove one to add another." |
| File | Image or PDF, 25 MB (gallery) / 5 MB (document) | The file's real type is read from its content; 1 MB for a photo after resizing; SHA-256 fingerprint | — | "The document is larger than 5 MB…" / "File type not allowed…" / "File fingerprint mismatch. Please retry the upload." |
| Mandatory photos | Slots show what is required | At submit | — | "Photograph required: {type}" |

## Submit
| Rule | Message |
|---|---|
| Confirmation ticked | "Please confirm that the information is correct." |
| Nothing missing (all the rules above) | "Submission blocked: some mandatory items are missing." The list has a Fix link for each item. |
| Not already submitted | "This inspection has already been submitted." |
| No changes still waiting on the device | "Some changes are still on this device. Connect to the network to send them first." |

## Database rules (MySQL CHECK constraints)

| Name | Table | Rule |
|---|---|---|
| `chk_fund_amounts` | inspection_fund_positions | Every amount is empty, or between 0 and 99,999 |
| `chk_fund_financial_year` | inspection_fund_positions | `FY-YYYY-YY` |
| `chk_inspections_completion_pct` | inspections | ≤ 100 |
| `chk_inspections_location` | inspections | Start / submit latitude and longitude in range, accuracy ≥ 0 |
| `chk_evidence_location` | inspection_evidence | The same, for photos |
| `chk_absent_teacher_type` / `chk_absent_reason` / `chk_absent_teacher_name` | inspection_absent_teachers | Listed values; name of at least 2 characters |
| `chk_classroom_class` / `chk_classroom_participation` / `chk_classroom_names` | inspection_classroom_observations | Listed values; name and subject of at least 2 characters |
| `chk_answers_step` | inspection_answers | Step 1 to 7 |

These rules were already in the database: one inspection per officer, school and day; answers unique per (inspection, question); `row_uid` unique per inspection; fund year unique per inspection; TLM pairs unique.

- The CHECKs run on MySQL 8.0.16+ and MariaDB 10.2+ only. Tests run on SQLite, where the form and server rules cover them.
- Before each CHECK is added, the existing rows are counted. A rule that old rows break is skipped and reported, so it never stops a deploy.
- On 26-11-2026 all 12 CHECKs were added on MySQL, and no existing row broke any of them.

## Tests
`tests/Feature/InspectionValidationTest.php` covers:
- hidden and unknown codes;
- values in the wrong form;
- numbers that are not whole;
- text that is too long or too short;
- follow-up answers cleared when their parent changes;
- absent teacher type against step 2;
- an absent teacher as the observed teacher;
- suggestion messages;
- photo type, time and location;
- the document limit.
