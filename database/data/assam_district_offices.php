<?php

/*
 * District headquarter (office) locations, keyed by District Master code, from
 * Documents/Lat Long of district headquarter.xlsx (matched by name: KARBIANGLONG, DIMAHASAO,
 * SOUTH-SALMARA-MANKACHAR and WEST-KARBIANGLONG are written with spaces in the master).
 * The file gave TAMULPUR the Baksa coordinates and left BAJALI blank: those two are approximate town
 * locations (Tamulpur, Pathsala) and can be corrected in the Location Master.
 * Used by migration 2026_11_25_000001_set_district_office_coordinates.
 */

return [
    '1801' => [26.403992, 90.272096],   // KOKRAJHAR
    '1802' => [26.019224, 89.99159],    // DHUBRI
    '1803' => [26.146818, 90.614703],   // GOALPARA
    '1804' => [26.449769, 90.563044],   // BONGAIGAON
    '1805' => [26.334347, 91.006614],   // BARPETA
    '1806' => [26.19336, 91.67353],     // KAMRUP-RURAL
    '1807' => [26.460435, 91.446762],   // NALBARI
    '1808' => [26.431137, 92.02093],    // DARRANG
    '1809' => [26.25111, 92.340117],    // MORIGAON
    '1810' => [26.353423, 92.695563],   // NAGAON
    '1811' => [26.620034, 92.793734],   // SONITPUR
    '1812' => [27.23763, 94.1071],      // LAKHIMPUR
    '1813' => [27.464673, 94.554656],   // DHEMAJI
    '1814' => [27.494596, 95.364972],   // TINSUKIA
    '1815' => [27.458319, 94.911244],   // DIBRUGARH
    '1816' => [26.983852, 94.635558],   // SIBSAGAR
    '1817' => [26.757222, 94.208056],   // JORHAT
    '1818' => [26.523851, 93.962334],   // GOLAGHAT
    '1819' => [25.839775, 93.43612],    // KARBI ANGLONG
    '1820' => [25.16104, 93.018294],    // DIMA HASAO
    '1821' => [24.82757, 92.791484],    // CACHAR
    '1822' => [24.872483, 92.359539],   // SRIBHUMI
    '1823' => [24.684772, 92.561955],   // HAILAKANDI
    '1824' => [26.662933, 91.343894],   // BAKSA
    '1825' => [26.52355, 90.49811],     // CHIRANG
    '1826' => [26.752374, 92.083542],   // UDALGURI
    '1827' => [26.17593, 91.744923],    // KAMRUP-METRO
    '1828' => [26.737342, 93.139792],   // BISWANATH
    '1829' => [27.021995, 95.005609],   // CHARAIDEO
    '1830' => [25.995995, 92.92556],    // HOJAI
    '1831' => [26.948636, 94.172914],   // MAJULI
    '1832' => [25.722495, 89.900035],   // SOUTH SALMARA-MANKACHAR
    '1833' => [25.932233, 92.703605],   // WEST KARBI ANGLONG
    '1834' => [26.6519, 91.567],        // TAMULPUR (approximate, Tamulpur town)
    '1835' => [26.5039, 91.1819],       // BAJALI (approximate, Pathsala)
];
