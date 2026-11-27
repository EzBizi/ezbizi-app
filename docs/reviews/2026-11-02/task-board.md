# Task Board: review, 2026-11-02

Measured on production through the API as Jose's Viewer key (GET only). Boards read: My board, the A-1 Chimney company board (which rolls up its 11 branch boards, 354 tasks, 2026-10-14 to 11-02), New York, New Jersey, Ohio and Massachusetts. Code read in app/Support/Tasks, the Task controllers, ActionRunner's task steps and client/lib/screens/task_board*.

## 1. Working

- **Board list** (GET /task-boards): 173 boards, all 200. Sections: 1 mine, 143 everyone, 29 places (10 company, 19 branch). No custom boards exist yet. Every row carries franchise_id, can_manage and my_role ('owner' on Jose's own board).
- **My board**: 4 statuses (Not started, Working on it, Stuck, Done), 5 groups, 0 tasks, members_preview and assignable are both Jose.
- **A-1 Chimney board**: 6 groups including Customer Experience. Columns are Description (status: Complaint / Bad Review), Client name and Phone number. Builtin labels read Owner = creator, Assigned = owner, and Created is hidden. 56 assignable, 4 in members_preview.
- **Branch tasks roll up** to the company board with their own board_id and group, as designed (dfd9c3e83).
- **Customer Experience placement**: all 347 Complaint call / Sad review tasks are in a Customer Experience group, none outside it.
- **Description filled**: all 347 CX tasks carry Description (314 complaint, 33 bad_review); no CX task has empty values.
- **Task detail** (GET /tasks/{id}): updates, about and created_by come back; no 500 on any read.
- **Members** (GET /task-boards/{id}/members): implicit members plus candidates with role names.
- **Owner-null complaint tasks have stopped**: all 93 unassigned Complaint call tasks were made 10-15 to 10-20; every one since 10-20 has Adeline + Jonathan.

## 2. Broken

1. **Duplicate tasks from one event.**
   - Evidence: identical pairs 3-4 s apart on the same lead: 01a168fd-7ed9 / 01a168fd-8c81 (10-23, Samantha) and 01a18523-92db / 01a18523-9ded (10-29). Also two "Paid: JOB-51758" tasks 3 s apart (01a16924-05c4 / -0f3f).
   - Why: `ezbizi:label-call-transcripts` emits `lead.call_complaint` once per conversation row (app/Console/Commands/LabelCallTranscripts.php:65). One call stored as two transcripts gives two events, and `task.create` had no guard against repeats.
   - **Fix: ea4d87a87** (session/task-board). A rule adds at most one task per record (lead/job) per 10 minutes; a second run is logged as skipped. Test: TaskStepsTest::test_one_rule_adds_one_task_per_record_in_ten_minutes. Existing duplicates are left alone (no prod rewrite).
2. **The unparsed task is still unparsed.**
   - Evidence: 01a145b5-2596-71a7-92d2-026e33e313a8 (10-16). Title is the raw "Complaint:   - Wants a price for a general wood-burning chimney cleaning.", with no phone and no owner.
   - Why: it was made before the summary and Phone-number values existed. A later task, 01a18486-2383 (10-28), also has no phone because the lead has none.
   - Not fixed. It needs a manual edit or delete, so it is listed in section 5.
3. **New York's board does not match the other branches.**
   - Evidence: Description is a `text` column holding the raw id "complaint" rather than the Complaint chip, Client name is missing, and Customer Experience has no colour.
   - Why: the board's CX group and columns were first made by the rule itself on 10-26 (ActionRunner::createTask makes a missing column as `text` and a missing group with no colour). The other branches were set up on 10-20 with the status column.
   - Not fixed in code. Changing the column type is a prod data change, so it is listed in section 5.
4. **Nothing is being worked.**
   - Evidence: 345 of 347 CX tasks are Not started, there is 1 update in total, 0 tasks have a due date, and the overdue job therefore has nothing to announce.
   - This is not a code defect. It is listed in section 5.

Seen outside my module, for its owner: in GET /automations, every "WhatsApp: …" schedule (including the two weekly ones) shows runs_this_week 22, and Weekly Work Hours shows 23, all with last_run_at 2026-11-02 14:36. Automations should check whether these are skipped runs or real sends.

## 3. Cohesiveness (for UI-UX)

- **Seven branch boards named "Main"** appear in the Places list with no company shown, so they cannot be told apart.
- **Stale board names**: 12 personal boards belong to a "Removed account" owner, and 5 more drift from the person's current name (e.g. "Danika Kaye Navarroza" vs owner "Danika Kaye"); one name is doubled ("Ailyn B. Malintad Ailyn B. Malintad").
- **Member candidates include 13 "Removed account" rows** and two "Artem Maliuk".
- **Group colours**: Customer Experience is red on most boards but uncoloured on New York, and "Project Management" groups made by rules have no colour.

## 4. Suggestions

1. Assign the 93 owner-less complaint tasks from 10-15 to 10-20 to Adeline + Jonathan. Payoff: they show on someone's list.
2. Give CX tasks a due date (e.g. next business day) in the Complaint call rule. Payoff: the existing overdue announcer starts working for complaints.
3. Make rule-created columns copy the type and options of the same-named column on the company board. Payoff: no more New York-style drift.
4. Archive or merge the personal boards of removed accounts, and drop removed accounts from member candidates. Payoff: a shorter, cleaner Everyone list.
5. Add a Tasks report (open / done / overdue per board and owner). Payoff: there is no tasks endpoint under /reports today, so nobody can see that 345 complaints sit untouched.

## 5. Needs Jose

1. Should someone own working the Customer Experience boards? 345 of 347 complaint tasks are Not started and only 1 has a comment.
2. May I set New York's Description column to the Complaint/Bad Review status type and add Client name, matching the other branches (prod data change, dumped first)?
3. Delete or fix the unparsed task 01a145b5 (10-16), and the duplicate pairs from 10-23 and 10-29?
4. Assign the 93 owner-less complaint tasks from 10-15 to 10-20 to Adeline + Jonathan?
