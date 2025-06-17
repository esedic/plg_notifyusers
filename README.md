# Joomla 5 Content Plugin - Notify users

== Description ==

Sends an email when new article is published."Joomla 5 compatible version

**Major Changes:**

* Namespace Implementation: Added proper namespace Joomla\Plugin\Content\Notifyusers\Extension
* Event Subscriber Interface: Implemented SubscriberInterface with getSubscribedEvents() method
* Database Aware Traits: Used DatabaseAwareInterface and DatabaseAwareTrait instead of direct database property
* Event Handling: Updated to use the new Event system with Event $event parameter
* Services Provider: Added the required services provider file for dependency injection
* Final Class: Made the class final as recommended for Joomla 5

**Additional Improvements:**

* Better Error Handling: Enhanced exception handling and logging
* Type Hints: Added proper type hints throughout
* Security: Added HTML escaping for the article title
* Code Quality: Improved variable naming and code structure
* Language Constants: Made email content translatable

**Language Files Updates:**

* New language constants