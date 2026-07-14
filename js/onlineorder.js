(function() {
  var app = angular.module('onlineorder',[]);

  app.controller('OrderCtrl', function() {
    this.oferr = false;
    this.venues = venues;
    this.provinces = provinces;
    this.schedule = schedule;
    this.courses = courses;
  });

  var venues = [ "", "Bellville, CPT", "Durban, KZN", "Durbanville, CPT", "Tygervalley, CPT", "Woodmead, JHB", "Port Elizabeth", "Self Study", "Online Video" ];

  var provinces = [ "",	"Western Cape", "Gauteng", "KwaZulu-Natal", "Eastern Cape", "Free State", "Limpopo", "Mpumalanga", "North-West", "Northern Cape", "Outside South Africa" ];

  var schedule = [
    {
      date: "AUGUST 16 2017",
      venue: "Midrand, JHB",
      course: "Imports into South Africa"
    },
    {
      date: "AUGUST 17 2017",
      venue: "Midrand, JHB",
      course: "Exports from South Africa"
    },
    {
      date: "SEPTEMBER 13 2017",
      venue: "Midrand, JHB",
      course: "VAT - PART I"
    },
    {
      date: "SEPTEMBER 14 2017",
      venue: "Midrand, JHB",
      course: "VAT - PART II"
    },
    {
      date: "OCTOBER 11 2017",
      venue: "Bellville, CPT",
      course: "VAT - Part I"
    },
    {
      date: "OCTOBER 12 2017",
      venue: "Bellville, CPT",
      course: "VAT - Part II"
    },
    {
      date: "OCTOBER 19 2017",
      venue: "WELGEMOED, CPT",
      course: "Basic Accounting to Trial Balance"
    },
    {
      date: "OCTOBER 26 2017",
      venue: "Welgemoed, CPT",
      course: "Imports into South Africa"
    },
    {
      date: "OCTOBER 27 2017",
      venue: "Welgemoed, CPT",
      course: "Exports from South Africa"
    }
  ];

  var courses = [
    {
      code: "W-IMPORTS-R2350",
      price: 2350,
      type: "Workshop",
      subject: "Imports into South Africa",
      description: "Designed for individuals and companies that import goods and services into the Republic of South Africa or want to start an importing business but do not know how to start the process.",
      available: true
    },
    {
      code: "W-EXPORTS-R2350",
      price: 2350,
      type: "Workshop",
      subject: "Exports from South Africa",
      description: "Designed for individuals and companies that export goods and services from the Republic of South Africa or want to start an exporting business but do not know how to start the process.",
      available: true
    },
    {
      code: "W-VAT1-R2350",
      price: 2350,
      type: "Workshop",
      subject: "VAT - Beginner - Part I",
      description: "The one day VAT for Beginners Workshop – Part I is designed for individuals, staff and financial management that are required to calculate and submit VAT returns for a business, and have little or no understanding of how VAT really works.",
      available: true
    },
    {
      code: "W-VAT2-R2350",
      price: 2350,
      type: "Workshop",
      subject: "VAT - Intermediate - Part II",
      description: "The one day VAT Intermediate Workshop – Part II is designed for individuals, staff and financial management currently working in a financial environment dealing with the VAT of the business, and require guidance with calculating this correctly as well as deducting the maximum VAT amount allowable.",
      available: true
    },
    {
      code: "W-TBAL-R2350",
      price: 2350,
      type: "Workshop",
      subject: "Basic Accounting To Trial Balance",
      description: "This workshop will help you understand how your books of account are constructed from source entry to trial balance. You will be able to recognize and understand the accounting entries that originate within your own business, enabling a more controlled environment within which your business can operate in.",
      available: true
    },
    {
      code: "W-PAYROL-R2350",
      price: 2350,
      type: "Workshop",
      subject: "Basic Payroll Procedures",
      description: "This workshop will help you understand how to construct and process the payroll effectively within an accounting environment, and fulfill the payroll returns and functions within a business. You will be able to recognize and understand how to integrate payroll within a business, that complies with relevant legislation.",
      available: true
    },
    {
      code: "W-TAX-R2350",
      price: 2350,
      type: "Workshop",
      subject: "Personal & Business Taxation",
      description: "This practical workshop, will cover aspects of both personal and business taxation that are very topical currently. The workshop is an update on recent developments effecting the tax environment within South Africa, and is recommended for all individuals, entrepreneurs and managers who run businesses in the SME environment.",
      available: true
    },
    {
  	  code: "W-UFS1-R2350",
  	  price: 2350,
  	  type: "Workshop",
  	  subject: "Understanding Financial Statements BASIC",
      description: "Financial Statements are essential to all businesses and the aim of this workshop is to provide delegates with practical skills in using accounting data to make decisions, and in reading financial statements (Management Accounts and or Annual Financial Statements) to effectively manage business, and to converse with their directors, accountants and bookkeepers on a more interactive level.",
  	  available: true
  	},
    {
  	  code: "W-UFS2-R2350",
  	  price: 2350,
  	  type: "Workshop",
  	  subject: "Understanding Financial Statements INTERMEDIATE",
      description: "Financial Statements are essential to all businesses and the aim of this workshop is to provide delegates with practical skills in using accounting data to make decisions, and in reading financial statements (Management Accounts and or Annual Financial Statements) to effectively manage business, and to converse with their directors, accountants and bookkeepers on a more interactive level.",
  	  available: true
  	},
    {
      code: "W-BUSVAL-R2350",
      price: 2350,
      type: "Workshop",
      subject: "Business Valuation & Shareholder agreements",
      description: "This workshop is designed to show the entrepreneur and other professionals, how to value a business. The workshop explores the three main methodologies in valuing a business, with emphases on using the right valuation method for the right circumstance, looking at the asset value, market value and income approach methods of valuing a business. To ascertain a fair market value of a business when buying & selling. Liquidity test as required by the Companies Act prior to declaring dividends. Placing a value to include or update in a Shareholder Agreement. Death of a shareholder, incapacitation, and the exit from a business by an owner for whatever reason. Estate valuation. Divorce.",
      available: true
    },
    {
      code: "W-SME-R2350",
      price: 2350,
      type: "Workshop",
      subject: "How to Start a Small to Medium size Enterprise in South Africa",
      description: "Biz Facility, accounting and tax training specialists,  now introduces this NEW practical workshop to assist and prepare you with starting your own business that will cover aspects that are practical and realistic. The workshop is practical within a South African context, and is recommended for all individuals, startups, entrepreneurs and managers who would like to start a business or who are currently running a small to medium enterprise.",
      available: true
    },
  	{
  	  code: "SS-INTRO-R970",
  	  price: 970,
  	  type: "Self Study",
  	  subject: "BASIC BUSINESS ACCOUNTING",
      description: "",
  	  available: true
  	},
  	{
  	  code: "SS-UFS-R970",
  	  price: 970,
  	  type: "Self Study",
  	  subject: "UNDERSTANDING FINANCIAL STATEMENTS",
      description: "",
  	  available: true
  	},
  	{
  	  code: "SS-AFS-R970",
  	  price: 970,
  	  type: "Self Study",
  	  subject: "FINANCIAL STATEMENTS RATIO ANALYSIS",
      description: "",
  	  available: true
  	},
  	{
  	  code: "SS-TST-R350",
  	  price: 350,
  	  type: "Self Study",
  	  subject: "Using Financial Statements In Measuring Sales To Cash Profitably - The Two Step Tango",
      description: "",
  	  available: false
  	},
  	{
  	  code: "SS-BUSVAL-R970",
  	  price: 970,
  	  type: "Self Study",
  	  subject: "BUSINESS VALUATION",
      description: "",
  	  available: true
  	},
  	{
  	  code: "SS-VAT-R1370",
  	  price: 1370,
  	  type: "Self Study",
  	  subject: "VAT PARTS I & II",
      description: "",
  	  available: true
  	},
  	{
  	  code: "SS-TAX-R970",
  	  price: 970,
  	  type: "Self Study",
  	  subject: "BUSINESS & PERSONAL TAX",
      description: "",
  	  available: true
  	},
  	{
  	  code: "SS-PAY-R650",
  	  price: 650,
  	  type: "Self Study",
  	  subject: "Impact Of Taxation On Payroll",
      description: "",
  	  available: false
  	},
  	{
  	  code: "SS-WILL-R650",
  	  price: 650,
  	  type: "Self Study",
  	  subject: "Understanding Wills, Trusts And Estate Duty",
      description: "",
  	  available: false
  	},
  	{
  	  code: "SS-TREND-R650",
  	  price: 650,
  	  type: "Self Study",
  	  subject: "Understanding Trend Investing Analysis For Shares Of The Stock Market",
      description: "",
  	  available: false
  	},
  	{
  	  code: "OV-UFS-R1370",
  	  price: 1370,
  	  type: "Online Video",
  	  subject: "UNDERSTANDING FINANCIAL STATEMENTS",
      description: "",
  	  available: true
  	},
  	{
  	  code: "OV-AFS-R1370",
  	  price: 1370,
  	  type: "Online Video",
  	  subject: "FINANCIAL STATEMENTS RATIO ANALYSIS",
      description: "",
  	  available: true
  	},
  	{
  	  code: "OV-TST-R1250",
  	  price: 1250,
  	  type: "Online Video",
  	  subject: "MANAGE CASH FLOW & PROFIT",
      description: "",
  	  available: true
  	},
  	{
  	  code: "OV-BUSVAL-R1570",
  	  price: 1570,
  	  type: "Online Video",
  	  subject: "HOW TO VALUE YOUR BUSINESS",
      description: "",
  	  available: true
  	},
  	{
  	  code: "OV-TAX-R1250",
  	  price: 1250,
  	  type: "Online Video",
  	  subject: "PERSONAL TAX",
      description: "",
  	  available: true
  	}
  ];

})();