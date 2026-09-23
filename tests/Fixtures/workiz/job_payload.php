<?php

// SHAPE CAPTURED FROM REAL PAYLOADS, VALUES SCRUBBED.
//
// Measured 2026-09-23 across 400 stored payloads in `a1tools_workiz_jobs` on
// the app box — A1 Tools has been storing real v1 responses for months, which
// is a far better control than the vendor's OpenAPI file. Every structural
// quirk below was found there, and every one of them breaks an importer
// written from the published spec:
//
//   - NOTHING IS EVER NULL. Absent is `''`, so `?? null` never fires.
//   - Keys change type between rows: Tags is string|array, Unit/PhoneExt/
//     SecondPhone/JobName are string|integer, the money fields integer|double.
//   - Dates are 'Y-m-d H:i:s' with NO zone, not the ISO-8601-with-Z the doc
//     shows. `Timezone` is documented but absent from every real row.
//   - ServiceArea carries a trailing space on four live values.
//   - The key set is OPEN: the account's own custom fields arrive at the top
//     level, undocumented, on most rows.
//
// The personal values here are invented — real ones are A-1's customers and do
// not belong in a repository. The SHAPE is not invented, and the shape is what
// this fixture is for.

return [
    'UUID' => 'DG0KYN',
    'SerialId' => 900018531,
    'ClientId' => 1002,
    'JobDateTime' => '2026-09-25 08:00:00',
    'JobEndDateTime' => '2026-09-25 11:00:00',
    'CreatedDate' => '2026-09-23 08:43:06',
    'LastStatusUpdate' => '2026-09-23 08:43:06',
    'PaymentDueDate' => '2026-09-25 00:00:00',
    'JobTotalPrice' => 0,
    'JobAmountDue' => 0,
    'SubTotal' => 0,
    'item_cost' => 0,
    'tech_cost' => 0,
    'Status' => 'Submitted',
    'SubStatus' => '',
    'Phone' => '5555550100',
    'SecondPhone' => '',
    'PhoneExt' => '',
    'SecondPhoneExt' => '',
    'Email' => 'someone@example.test',
    'Comments' => '',
    'FirstName' => 'Pat',
    'LastName' => 'Rivera',
    'Company' => '',
    'Address' => '1 Example Street',
    'Unit' => '',
    'City' => 'Hoboken',
    'State' => 'New York',
    'PostalCode' => '07030',
    'Country' => 'US',
    'Latitude' => 40.7440,
    'Longitude' => -74.0324,
    'JobType' => 'Free Estimate',
    'JobName' => 'Free Bid Estimate',
    'JobSource' => 'LS Ads',
    'JobNotes' => '',
    'CreatedBy' => 'Trey Brannan',
    'ServiceArea' => 'Upstate New York ',
    'Tags' => '',
    'Team' => [],
    'LineItems' => [],
    'LocationId' => 55512,
    'LocationKey' => 'loc_example',

    // Undocumented, account-specific, present on 232-354 of 400 real rows.
    'pricing' => '$149 - Level 2 Inspection',
    'system_type' => 'Gas System',
    'real_wood_y/n' => 'Appliance',
    'time_frames' => 'Appointment Time: 8-11',
];
