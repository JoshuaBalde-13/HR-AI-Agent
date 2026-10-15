<?php

return [

    // Documents every employee must have on file.
    // Write each one exactly like the document_type text in the employee_documents table.
    'required_employee_documents' => [
        'Government ID',
        'NBI Clearance',
        'Medical Certificate',
        'Security License',
    ],

    // A document that ends within this many days is urgent (HIGH priority).
    'urgent_days' => 7,

    // A document that ends within this many days needs attention (MEDIUM priority).
    'warning_days' => 30,

];