<?php

/*
 * Government of Assam, General Administration Department: "List of holidays for the year 2026 in the State of Assam",
 * e-file No.221561/440, Dispur, 17-11-2025 (Documents/HolidayList2026.pdf). Loaded by AssamHolidaySeeder and used for
 * the filled Excel template (HolidayTemplateExport, ?fill=assam-2026).
 *
 * state: [date, name, type]  gazetted = para 1 (offices closed), half = para 2 (half holiday from 1 PM),
 *                            restricted = para 5 (an employee may take 2; offices open).
 * local: para 9, Ali-Aye-Ligang is a full holiday in 11 districts (restricted elsewhere), matched by district code,
 *        else by name. The NI Act list (para 3) repeats para 1 and is not loaded; 2nd / 4th Saturdays are not loaded.
 */

return [
    'source' => 'GAD notification e-file No.221561/440 dated 17-11-2025',

    'state' => [
        // 1. Holidays
        ['2026-01-14', 'Magh Bihu & Tusu Puja', 'gazetted'],
        ['2026-01-15', 'Magh Bihu & Tusu Puja', 'gazetted'],
        ['2026-01-23', "Netaji's Birthday", 'gazetted'],
        ['2026-01-26', 'Republic Day', 'gazetted'],
        ['2026-01-27', 'Gwthar Bathou San', 'gazetted'],
        ['2026-01-31', 'Me-Dam-Me-Phi', 'gazetted'],
        ['2026-02-01', 'Bir Chilaray Divas', 'gazetted'],
        ['2026-03-03', 'Dol Jatra', 'gazetted'],
        ['2026-03-21', 'Id-Ul-Fitr', 'gazetted'],
        ['2026-04-03', 'Good Friday', 'gazetted'],
        ['2026-04-14', 'Bohag Bihu', 'gazetted'],
        ['2026-04-15', 'Bohag Bihu', 'gazetted'],
        ['2026-04-16', 'Bohag Bihu', 'gazetted'],
        ['2026-04-18', 'Tithi of Damodar Deva', 'gazetted'],
        ['2026-04-21', 'Sati Sadhini Divas', 'gazetted'],
        ['2026-05-01', 'May Day & Budha Purnima', 'gazetted'],
        ['2026-05-27', 'Id-ul-Zuha', 'gazetted'],
        ['2026-06-01', 'Janmotsav of Sri Sri Madhabdeva', 'gazetted'],
        ['2026-08-15', 'Independence Day', 'gazetted'],
        ['2026-09-01', 'Tirubhav Tithi of Sri Sri Madhabdeva', 'gazetted'],
        ['2026-09-04', 'Janmastomi', 'gazetted'],
        ['2026-09-12', 'Tirubhav Tithi of Srimanta Sankardeva', 'gazetted'],
        ['2026-09-21', 'Janmotsav of Srimanta Sankardeva', 'gazetted'],
        ['2026-09-22', 'Karam Puja', 'gazetted'],
        ['2026-10-02', 'Birthday of Mahatma Gandhi', 'gazetted'],
        ['2026-10-18', 'Kati Bihu & Durga Puja', 'gazetted'],
        ['2026-10-19', 'Durga Puja & Vijaya Dashomi', 'gazetted'],
        ['2026-10-20', 'Durga Puja & Vijaya Dashomi', 'gazetted'],
        ['2026-10-21', 'Durga Puja & Vijaya Dashomi', 'gazetted'],
        ['2026-11-08', 'Kali Puja & Diwali', 'gazetted'],
        ['2026-11-11', 'Bhatri Dwitiya', 'gazetted'],
        ['2026-11-15', 'Chhath Puja', 'gazetted'],
        ['2026-11-24', "Guru Nanak's Birthday & Lachit Divas", 'gazetted'],
        ['2026-12-02', 'Asom Divas (Su-Ka-Pha Divas)', 'gazetted'],
        ['2026-12-25', 'Christmas Day', 'gazetted'],

        // 2. Half holiday (from 1 PM)
        ['2026-10-25', 'Lakshmi Puja', 'half'],

        // 5. Restricted holidays
        ['2026-01-01', "New Year's Day", 'restricted'],
        ['2026-01-05', 'Rongker Karkli', 'restricted'],
        ['2026-01-13', 'Uruka of Magh Bihu', 'restricted'],
        ['2026-01-17', 'Silpi Divas', 'restricted'],
        ['2026-01-27', 'Busu Dima Festival', 'restricted'],
        ['2026-02-04', 'Shab-E-Barat', 'restricted'],
        ['2026-02-15', 'Sivaratri', 'restricted'],
        ['2026-02-17', 'Karbi-Dehal-Kachir Dom', 'restricted'],
        ['2026-02-18', 'Ali Aye Ligang', 'restricted'],
        ['2026-03-02', 'Khring Khring Baithow Puja', 'restricted'],
        ['2026-03-17', 'Lailatur Qadar', 'restricted'],
        ['2026-03-25', 'Tora Chira Bihu', 'restricted'],
        ['2026-03-31', 'Mahabir Jayanti', 'restricted'],
        ['2026-04-04', 'Easter Saturday', 'restricted'],
        ['2026-04-15', 'Deuri Bihu', 'restricted'],
        ['2026-04-18', 'Birthday of Gurudev Kalicharan Brahma', 'restricted'],
        ['2026-04-22', 'Tithi of Gopal Dev', 'restricted'],
        ['2026-05-01', 'Sati Radhika Utsav', 'restricted'],
        ['2026-05-16', 'Tithi of Hari Dev', 'restricted'],
        ['2026-05-23', 'Birthday of Sri Sri Krishnaguru', 'restricted'],
        ['2026-05-24', 'Motak Roja Swargadeo Sarbananda Singha Divas', 'restricted'],
        ['2026-05-31', 'Baikhowa Festival', 'restricted'],
        ['2026-06-20', 'Death Anniversary of Bishnu Prasad Rabha', 'restricted'],
        ['2026-06-30', 'Hul Divas', 'restricted'],
        ['2026-07-13', 'Birthday of Nepali Poet Bhanu Bhakta Acharya', 'restricted'],
        ['2026-08-13', 'Death Anniversary of Bir Tikendrajit', 'restricted'],
        ['2026-08-26', 'Fateha-e-Dwaz-Daham', 'restricted'],
        ['2026-09-15', 'Nau Khai Festival', 'restricted'],
        ['2026-09-16', 'Birthday of Sri Sri Thakur Anukul Chandra', 'restricted'],
        ['2026-11-01', 'Kuki Chavang Kut', 'restricted'],
        ['2026-11-07', 'Wangala Festival', 'restricted'],
        ['2026-11-11', 'Abhibhav Tithi of Sri Sri Bhubaneswar Sadhu Thakur', 'restricted'],
        ['2026-11-19', 'Bir Raghav Moran Divas', 'restricted'],
        ['2026-11-24', 'Death Anniversary of Guru Teg Bahadur Ji', 'restricted'],
        ['2026-12-05', 'Sikpui Ruoi (Harvest Festival of Hmar Community)', 'restricted'],
        ['2026-12-10', "Martyr's Day", 'restricted'],
        ['2026-12-19', 'Death Anniversary of Shree Shree Aniruddhadev', 'restricted'],
        ['2026-12-22', 'Gaan Ngai', 'restricted'],
        ['2026-12-24', 'Christmas Eve', 'restricted'],
    ],

    // 9. Ali-Aye-Ligang: full holiday in these districts (code => name as in the notification)
    'local' => [
        ['2026-02-18', 'Ali Aye Ligang', 'gazetted', [
            '1813' => 'DHEMAJI', '1812' => 'LAKHIMPUR', '1811' => 'SONITPUR', '1814' => 'TINSUKIA', '1815' => 'DIBRUGARH',
            '1816' => 'SIVASAGAR', '1817' => 'JORHAT', '1818' => 'GOLAGHAT', '1831' => 'MAJULI', '1829' => 'CHARAIDEO', '1828' => 'BISWANATH',
        ]],
    ],
];
