---
name: User Approval System Issues (Reject Flow)
about: Create a report to help us improve
title: ''
labels: bug
assignees: carlbnj7, Jabezbinayao, jowseeph

---

**Describe the bug**
The system has issues in handling rejected users. When an admin rejects a pending user, there is no proper tracking or notification. Rejected users can still log in, but they are shown an empty dashboard, which creates confusion and indicates weak access control.

**To Reproduce**
Steps to reproduce the behavior:
1. Register a new user account
2. Log in as admin
3. Go to pending users
4. Reject a user
5. Log in using the rejected account
6. See empty dashboard and no restriction

**Expected behavior**
- Rejected users should not be able to log in
- System should notify users when their account is rejected
- Rejected users should be stored or visible in a separate list/page
- Proper access restriction should be enforced

**Screenshots**
If applicable, add screenshots to help explain your problem.

**Desktop (please complete the following information):**
 - OS: Windows
 - Browser Chrome
 - Version [e.g. 22]

**Smartphone (please complete the following information):**
 - Device: [e.g. iPhone6]
 - OS: [e.g. iOS8.1]
 - Browser [e.g. stock browser, safari]
 - Version [e.g. 22]

**Additional context**
This issue affects user experience and system security
