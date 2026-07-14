(function() {
  var app = angular.module('onlineorder',[]);

  app.controller('OrderCtrl', function() {
    this.oferr = false;
    this.venues = venues;
    this.provinces = provinces;
    this.schedule = schedule;
    this.courses = courses;
  });

  var venues = [ "", "Bellville, CPT", "Durban, KZN", "Durbanville, CPT", "Tygervalley, CPT", "Woodmead, JHB", "Self Study", "Online Video" ];

  var provinces = [ "",	"Western Cape", "Gauteng", "KwaZulu-Natal", "Eastern Cape", "Free State", "Limpopo", "Mpumalanga", "North-West", "Northern Cape", "Outside South Africa" ];

  var schedule = [
    {
      date: "MAY 25-26 2015",
      venue: "Rosebank, JHB",
      course: "Delivering Exceptional Customer Service"
    },
    {
      date: "JUNE 02-03 2015",
      venue: "Rosebank, JHB",
      course: "People management and Supervisory Skills"
    },
    {
      date: "JUNE 04-05 2015",
      venue: "Rosebank, JHB",
      course: "Minute Taking Made Simple"
    },
    {
      date: "JUNE 08-09 2015",
      venue: "Rosebank, JHB",
      course: "Professional Development for PA’s and Secretaries"
    },
    {
      date: "JUNE 10 2015",
      venue: "Rosebank, JHB",
      course: "The Professional Receptionist"
    },
    {
      date: "JUNE 22-23 2015",
      venue: "Rosebank, JHB",
      course: "Communicating Effectively in English"
    },
    {
      date: "JULY 06-07 2015",
      venue: "Rosebank, JHB",
      course: "Delivering Exceptional Customer Service"
    },
    {
      date: "JULY 13-14 2015",
      venue: "Rosebank, JHB",
      course: "Professional Office Administration"
    },
    {
      date: "JULY 20-21 2015",
      venue: "Rosebank, JHB",
      course: "Business Writing for Office Professionals"
    },
    {
      date: "JULY 28-29 2015",
      venue: "Rosebank, JHB",
      course: "Assertiveness and Confidence at Work"
    },
    {
      date: "AUGUST 03-04 2015",
      venue: "Rosebank, JHB",
      course: "Professional Development for PA’s and Secretaries"
    },
    {
      date: "AUGUST 05 2015",
      venue: "Rosebank, JHB",
      course: "The Professional Receptionist"
    },
    {
      date: "AUGUST 19-20 2015",
      venue: "Rosebank, JHB",
      course: "Communicating Effectively in English"
    },
    {
      date: "AUGUST 24-25 2015",
      venue: "Rosebank, JHB",
      course: "People management and Supervisory Skills"
    },
    {
      date: "AUGUST 26-27 2015",
      venue: "Rosebank, JHB",
      course: "Minute Taking Made Simple"
    },
    {
      date: "SEPTEMBER 02-03 2015",
      venue: "Rosebank, JHB",
      course: "The Joburg Secretaries Day Conference"
    },
    {
      date: "SEPTEMBER 10-11 2015",
      venue: "Rosebank, JHB",
      course: "Delivering Exceptional Customer Service"
    },
    {
      date: "SEPTEMBER 14-15 2015",
      venue: "Rosebank, JHB",
      course: "Professional Office Administration"
    },
    {
      date: "SEPTEMBER 17-18 2015",
      venue: "Rosebank, JHB",
      course: "Business Writing for Office Professionals"
    },
    {
      date: "SEPTEMBER 29-30 2015",
      venue: "Rosebank, JHB",
      course: "Assertiveness and Confidence at Work"
    }
  ];

  var courses = [
    {
      code: "W-ACW-R4250",
      price: 4250,
      type: "Workshop",
      subject: "Assertiveness and Confidence at Work (2 Days)",
      description: "On this course we will provide you with practical guidance to develop your assertiveness skills in any work situation. You will leave the course with a ‘Personal Action Plan’, knowing what key changes you need to make, and what skills you need to practice, in order to build your self-esteem and become more assertive.",
      available: true
    },
    {
      code: "W-BWOP-R4250",
      price: 4250,
      type: "Workshop",
      subject: "Business Writing for Office Professionals (2 Days)",
      description: "The course covers a wide range of business documents including reports, e-mails, letters, and proposals. You will be given practical techniques and skills that, when implemented, will make a real difference to the documents you write. Ultimately, this course will help you to write more confidently and clearly.",
      available: true
    },
    {
      code: "W-CEE-R4250",
      price: 4250,
      type: "Workshop",
      subject: "Communicating Effectively in English (2 Days)",
      description: "The course will help learners speak English more clearly and coherently, whether face-to-face or over the phone. It provides an excellent guide on how to cope with the various challenges that second language English speakers face, on a daily basis.",
      available: true
    },
    {
      code: "W-DECS-R4250",
      price: 4250,
      type: "Workshop",
      subject: "Delivering Exceptional Customer Service (2 Days)",
      description: "Delivering exceptional customer service is about so much more than a friendly voice on the other side of the line. It’s about commitment, attitude, knowing your business and understanding your customer’s needs. At the course we create an environment where you can solve problems, share expertise and polish your skills, so that you renew your commitment to your work and your customers.",
      available: true
    },
    {
      code: "W-MTMS-R4250",
      price: 4250,
      type: "Workshop",
      subject: "Minute Taking Made Simple (2 Days)",
      description: "The workshop focuses on meeting preparation, meeting procedure and practical exercises that will aid you when writing minutes. Classroom exercises will enable delegates to practice their new skills in listening, note taking, summarising and selecting the most important points when writing and compiling minutes.",
      available: true
    },
    {
      code: "W-PMSS-R4250",
      price: 4250,
      type: "Workshop",
      subject: "People management and Supervisory Skills (2 Days)",
      description: "Managing and supervising people can be a daunting experience, as the list of responsibilities is endless, from leading and motivating your team to dealing with difficult people and managing poor performance. This course helps managers build their confidence and gives you a toolkit of essential skills and techniques that can be applied immediately back in the workplace.",
      available: true
    },
    {
      code: "W-PDPS-R4250",
      price: 4250,
      type: "Workshop",
      subject: "Professional Development for PA’s and Secretaries (2 Days)",
      description: "A good PA makes an enormous contribution to the effectiveness of her manager. Supporting your manager and being part of the management team, requires that today’s successful PA be proactive and have a range of communication and professional skills. This practical course will help you understand your role and find solutions to common problems, whilst sharing your experiences with other PA’s.",
      available: true
    },
    {
      code: "W-POA-R4250",
      price: 4250,
      type: "Workshop",
      subject: "Professional Office Administration (2 Days)",
      description: "In any organisation, the administrators and support staff have a variety of challenging roles and responsibilities. Administrators have to be flexible and responsive to the changing needs of their managers and team. This workshop will introduce you to new skills that will help increase your performance and the support you offer your team, department and manager.",
      available: true
    },
    {
      code: "W-PR-R2400",
      price: 2400,
      type: "Workshop",
      subject: "The Professional Receptionist (1 Day)",
      description: "At this course we will reinforce the importance of the receptionist’s role, highlighting how you influence your customers, both face-to-face and on the telephone. Being the public face and voice of your organisation means that you need to have excellent communication skills and conduct yourself in a professional manner. In a supportive environment, we will equip you with the key telephone, communication and customer care skills that you need to succeed.",
      available: true
    },
    {
      code: "C-JSDC-R5800",
      price: 5800,
      type: "Conference",
      subject: "Joburg Secretaries Day Conference (2 Days)",
      description: "This annual event, now in it’s 8th year, is South Africa’s premier training event for administrative professionals. Don’t miss this event if you are an executive assistant, personal assistant or in an admin role. Amazing line up of speakers, great networking, fabulous goodie bags and prizes.",
      available: true
    }
  ];

})();