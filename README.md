Project Description
The FCIT Student Complaint Web Application is a secure platform designed for the Faculty of Computing and Information Technology (FCIT) to let students voice concerns while protecting their identities.
To ensure authenticity, students must authenticate using their official university Gmail accounts. When a student submits a complaint, the system maps it to their account backend via the student_id foreign key. However, across all public fields or standard user interfaces, the complaint filer's identity is strictly anonymous. Only authorized administrative staff accessing the secure Admin Portal have the system clearance to pull the user profile and identify the filer for genuine resolution and auditing purposes.

Functional Requirements (FRs)
Functional requirements define what the web application must do to support user actions and business logic.
1. User Management & Authentication
Official Domain Restriction: The system must restrict registration exclusively to email addresses ending with the official university domain (e.g., @pucit.edu.pk or the designated student Gmail structure).
Role-Based Access Control (RBAC): The system must differentiate between student and admin roles upon login, dynamically serving the appropriate dashboard interface.
Profile Safeguards: Students must be able to securely update their passwords, while their primary official_email remains uneditable after verification.
2. Complaint Submission & Lifecycle
Categorized Filing: Students must be able to select a pre-defined category (e.g., Academics, Facilities, IT Support) when lodging a complaint to ensure automated routing.
Anonymous Flagging: The system must default the is_anonymous status flag to true, stripping all user identity headers from general views.
Status Tracking: Students must be able to track the progression of their submitted cases through linear stages: submitted  in_review  resolved or dismissed.
3. Admin Portal & Case Management
Identity Reveal Mechanism: The Admin Portal must provide authorized administrators with a decrypted or mapped view showing the exact full_name and official_email of the student attached to a specific complaint_id.
Action & Resolution Logs: Admins must be able to change complaint statuses and log administrative notes (notes), which automatically generates a permanent tracking record in the ADMIN_ACTIONS history table.
Category Configuration: Admins must have full CRUD capabilities (Create, Read, Update, Delete) over the CATEGORIES database table to dynamically adapt to new university issues.

Non-Functional Requirements (NFRs)
Non-functional requirements specify the criteria, quality attributes, and constraints used to judge the operation of the system.
1. Security & Privacy (Core Constraint)
Identity Isolation: The user identity must be isolated at the database mapping layer. No frontend UI components served to student accounts or general feeds should ever fetch or expose the student_id or student details of a third-party complaint.
Credential Protection: All passwords must be hashed using strong industry-standard cryptographic algorithms before storing them in the password_hash column.
2. Performance & Reliability
Query Latency: Relational queries linking COMPLAINTS to CATEGORIES for user dashboards must execute in under 500ms under normal peak university operational hours.
High Availability: The portal should maintain a 99.5% uptime during active semesters so students can log urgent issues at any time.
3. Data Integrity & Auditing
Immutable Logs: Every administrative action that modifies a complaint status must create a timestamped row in ADMIN_ACTIONS. Once a log is generated (created_at), it must be read-only and completely unalterable to prevent internal tampering.
Cascading Safeguards: Foreign key constraints must prevent the deletion of a student profile if they have active COMPLAINTS records attached to them, maintaining structural database integrity.

