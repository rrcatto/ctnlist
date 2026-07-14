/*jslint browser: true*/
/*global Vue*/
(function () {
  'use strict';
  var bizf = new Vue({
    el: '#bizf',
    data: {
      courses: [
        {
          code: "W-BASACC-R6000",
          price: 6000,
          type: "Workshop",
          length: "1-day",
          subject: "Basic Accounting for Business",
          description: "This workshop will help you understand how your books of account are constructed from source entry to trial balance. You will be able to recognize and understand the accounting entries that originate within your own business, enabling a more controlled environment within which your business can operate in.",
          available: true
        },
        {
          code: "W-FINADMIN-R6000",
          price: 6000,
          type: "Workshop",
          length: "1-day",
          subject: "Financial Administration",
          description: "",
          available: true
        },
        {
          code: "W-VAT1-R6000",
          price: 6000,
          type: "Workshop",
          length: "1-day",
          subject: "VAT Basic",
          description: "Delegates will gain an excellent and indepth understanding of how VAT works and is applied in a practical environment.",
          available: true
        },
        {
          code: "W-VAT2-R6000",
          price: 6000,
          type: "Workshop",
          length: "1-day",
          subject: "VAT Intermediate",
          description: "Delegates will gain an excellent and indepth understanding of how VAT works and is applied in a practical environment.",
          available: true
        },
        {
          code: "W-IMP-R6000",
          price: 6000,
          type: "Workshop",
          length: "1-day",
          subject: "Imports into South Africa",
          description: "Designed for individuals and companies that import or export goods and services into / out of the Republic of South Africa or want to start an importing or exporting business but do not know how to start the process.",
          available: true
        },
        {
          code: "W-EXP-R6000",
          price: 6000,
          type: "Workshop",
          length: "1-day",
          subject: "Exports out of South Africa",
          description: "Designed for individuals and companies that import or export goods and services into / out of the Republic of South Africa or want to start an importing or exporting business but do not know how to start the process.",
          available: true
        },
        {
          code: "W-UFS-R6000",
          price: 6000,
          type: "Workshop",
          length: "1-day",
          subject: "Understanding Financial Statements",
          description: "Financial Statements are essential to all businesses and the aim of this workshop is to provide delegates with practical skills in using accounting data to make decisions, and in reading financial statements (Management Accounts and or Annual Financial Statements) to effectively manage business, and to converse with their directors, accountants and bookkeepers on a more interactive level.",
          available: true
        },
        {
          code: "W-PBT-R6000",
          price: 6000,
          type: "Workshop",
          length: "1 day",
          subject: "Personal & Business Taxation",
          description: "This practical workshop, will cover aspects of both personal and business taxation that are very topical currently. The workshop is an update on recent developments effecting the tax environment within South Africa, and is recommended for all individuals, entrepreneurs and managers who run businesses in the SME environment.",
          available: true
        },
        {
          code: "W-DEBTCOL-R6000",
          price: 6000,
          type: "Workshop",
          length: "1-day",
          subject: "DEBT Collection",
          description: "",
          available: true
        },
        {
          code: "W-DEFER-R6000",
          price: 6000,
          type: "Workshop",
          length: "1 day",
          subject: "SARS: Tax Compromise & Deferment",
          description: "Do you know how to approach SARS, to enter into an agreement for a tax reduction or writing off of outstanding taxes?",
          available: true
        },
        {
          code: "W-CPYREG-R6000",
          price: 6000,
          type: "Workshop",
          length: "1 day",
          subject: "Company Registration, Changes & CIPC",
          description: "",
          available: true
        },
        {
          code: "W-TRUSTS-R6000",
          price: 6000,
          type: "Workshop",
          length: "1 day",
          subject: "Trusts, Wills & Estate Planning",
          description: "",
          available: true
        },
        {
          code: "W-BUSVAL-R6000",
          price: 6000,
          type: "Workshop",
          length: "1 day",
          subject: "Business Valuation & Shareholder agreements",
          description: "This workshop is designed to show the entrepreneur and other professionals, how to value a business. The workshop explores the three main methodologies in valuing a business, with emphases on using the right valuation method for the right circumstance, looking at the asset value, market value and income approach methods of valuing a business. To ascertain a fair market value of a business when buying & selling. Liquidity test as required by the Companies Act prior to declaring dividends. Placing a value to include or update in a Shareholder Agreement. Death of a shareholder, incapacitation, and the exit from a business by an owner for whatever reason. Estate valuation. Divorce.",
          available: true
        },
        {
          code: "W-PAYROL-R6000",
          price: 6000,
          type: "Workshop",
          length: "1-day",
          subject: "Basic Payroll Procedures",
          description: "This workshop will help you understand how to construct and process the payroll effectively within an accounting environment, and fulfill the payroll returns and functions within a business. You will be able to recognize and understand how to integrate payroll within a business, that complies with relevant legislation.",
          available: true
        },
        {
          code: "W-DIRECTOR-R6000",
          price: 6000,
          type: "Workshop",
          length: "1 day",
          subject: "Directors Responsibility & Requirements",
          description: "",
          available: false
        },
        {
          code: "W-SME-R6000",
          price: 6000,
          type: "Workshop",
          length: "1-day",
          subject: "How to Start an SME in South Africa",
          description: "Biz Facility, accounting and tax training specialists,  now introduces this NEW practical workshop to assist and prepare you with starting your own business that will cover aspects that are practical and realistic. The workshop is practical within a South African context, and is recommended for all individuals, startups, entrepreneurs and managers who would like to start a business or who are currently running a small to medium enterprise.",
          available: false
        },
        {
          code: "W-SALES-R6000",
          price: 6000,
          type: "Seminar",
          length: "Full day",
          subject: "Consultative Sales",
          description: "sales seminar",
          available: false
        },
        {
          code: "N-BASACC-R2500",
          price: 2500,
          type: "Course Notes",
          length: "1-day",
          subject: "Basic Accounting for Business",
          description: "This workshop will help you understand how your books of account are constructed from source entry to trial balance. You will be able to recognize and understand the accounting entries that originate within your own business, enabling a more controlled environment within which your business can operate in.",
          available: true
        },
        {
          code: "N-FINADMIN-R2500",
          price: 2500,
          type: "Course Notes",
          length: "1-day",
          subject: "Financial Administration",
          description: "",
          available: true
        },
        {
          code: "N-VAT1-R2500",
          price: 2500,
          type: "Course Notes",
          length: "1-day",
          subject: "VAT Basic",
          description: "Delegates will gain an excellent and indepth understanding of how VAT works and is applied in a practical environment.",
          available: true
        },
        {
          code: "N-VAT2-R2500",
          price: 2500,
          type: "Course Notes",
          length: "1-day",
          subject: "VAT Intermediate",
          description: "Delegates will gain an excellent and indepth understanding of how VAT works and is applied in a practical environment.",
          available: true
        },
        {
          code: "N-IMP-R2500",
          price: 2500,
          type: "Course Notes",
          length: "1-day",
          subject: "Imports into South Africa",
          description: "Designed for individuals and companies that import or export goods and services into / out of the Republic of South Africa or want to start an importing or exporting business but do not know how to start the process.",
          available: true
        },
        {
          code: "N-EXP-R2500",
          price: 2500,
          type: "Course Notes",
          length: "1-day",
          subject: "Exports out of South Africa",
          description: "Designed for individuals and companies that import or export goods and services into / out of the Republic of South Africa or want to start an importing or exporting business but do not know how to start the process.",
          available: true
        },
        {
          code: "N-UFS-R2500",
          price: 2500,
          type: "Course Notes",
          length: "1-day",
          subject: "Understanding Financial Statements",
          description: "Financial Statements are essential to all businesses and the aim of this workshop is to provide delegates with practical skills in using accounting data to make decisions, and in reading financial statements (Management Accounts and or Annual Financial Statements) to effectively manage business, and to converse with their directors, accountants and bookkeepers on a more interactive level.",
          available: true
        },
        {
          code: "N-PBT-R2500",
          price: 2500,
          type: "Course Notes",
          length: "1 day",
          subject: "Personal & Business Taxation",
          description: "This practical workshop, will cover aspects of both personal and business taxation that are very topical currently. The workshop is an update on recent developments effecting the tax environment within South Africa, and is recommended for all individuals, entrepreneurs and managers who run businesses in the SME environment.",
          available: true
        },
        {
          code: "N-DEBTCOL-R2500",
          price: 2500,
          type: "Course Notes",
          length: "1-day",
          subject: "DEBT Collection",
          description: "",
          available: true
        },
        {
          code: "N-DEFER-R2500",
          price: 2500,
          type: "Course Notes",
          length: "1 day",
          subject: "SARS: Tax Compromise & Deferment",
          description: "Do you know how to approach SARS, to enter into an agreement for a tax reduction or writing off of outstanding taxes?",
          available: true
        },
        {
          code: "N-CPYREG-R2500",
          price: 2500,
          type: "Course Notes",
          length: "1 day",
          subject: "Company Registration, Changes & CIPC",
          description: "",
          available: true
        }
      ]
    }
  });

}());