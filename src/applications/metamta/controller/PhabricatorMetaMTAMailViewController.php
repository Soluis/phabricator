<?php

final class PhabricatorMetaMTAMailViewController
  extends PhabricatorMetaMTAController {

  public function handleRequest(AphrontRequest $request) {
    $viewer = $this->getViewer();

    $mail = id(new PhabricatorMetaMTAMailQuery())
      ->setViewer($viewer)
      ->withIDs(array($request->getURIData('id')))
      ->executeOne();
    if (!$mail) {
      return new Aphront404Response();
    }

    if ($mail->hasSensitiveContent()) {
      $title = pht('Content Redacted');
    } else {
      $title = $mail->getSubject();
    }

    $header = id(new PHUIHeaderView())
      ->setHeader($title)
      ->setUser($viewer)
      ->setPolicyObject($mail);

    $status = $mail->getStatus();
    $name = PhabricatorMailOutboundStatus::getStatusName($status);
    $icon = PhabricatorMailOutboundStatus::getStatusIcon($status);
    $color = PhabricatorMailOutboundStatus::getStatusColor($status);
    $header->setStatus($icon, $color, $name);

    $crumbs = $this->buildApplicationCrumbs()
      ->addTextCrumb(pht('Mail %d', $mail->getID()));

    $object_box = id(new PHUIObjectBoxView())
      ->setHeader($header)
      ->addPropertyList($this->buildMessageProperties($mail), pht('Message'))
      ->addPropertyList($this->buildHeaderProperties($mail), pht('Headers'))
      ->addPropertyList($this->buildDeliveryProperties($mail), pht('Delivery'))
      ->addPropertyList($this->buildMetadataProperties($mail), pht('Metadata'));

    return $this->buildApplicationPage(
      array(
        $crumbs,
        $object_box,
      ),
      array(
        'title' => $title,
        'pageObjects' => array($mail->getPHID()),
      ));
  }

  private function buildMessageProperties(PhabricatorMetaMTAMail $mail) {
    $viewer = $this->getViewer();

    $properties = id(new PHUIPropertyListView())
      ->setUser($viewer)
      ->setObject($mail);

    if ($mail->getFrom()) {
      $from_str = $viewer->renderHandle($mail->getFrom());
    } else {
      $from_str = pht('Sent by Phabricator');
    }
    $properties->addProperty(
      pht('From'),
      $from_str);

    if ($mail->getToPHIDs()) {
      $to_list = $viewer->renderHandleList($mail->getToPHIDs());
    } else {
      $to_list = pht('None');
    }
    $properties->addProperty(
      pht('To'),
      $to_list);

    if ($mail->getCcPHIDs()) {
      $cc_list = $viewer->renderHandleList($mail->getCcPHIDs());
    } else {
      $cc_list = pht('None');
    }
    $properties->addProperty(
      pht('Cc'),
      $cc_list);

    $properties->addProperty(
      pht('Sent'),
      phabricator_datetime($mail->getDateCreated(), $viewer));

    $properties->addSectionHeader(
      pht('Message'),
      PHUIPropertyListView::ICON_SUMMARY);

    if ($mail->hasSensitiveContent()) {
      $body = phutil_tag(
        'em',
        array(),
        pht(
          'The content of this mail is sensitive and it can not be '.
          'viewed from the web UI.'));
    } else {
      $body = phutil_tag(
        'div',
        array(
          'style' => 'white-space: pre-wrap',
        ),
        $mail->getBody());
    }

    $properties->addTextContent($body);


    return $properties;
  }

  private function buildHeaderProperties(PhabricatorMetaMTAMail $mail) {
    $viewer = $this->getViewer();

    $properties = id(new PHUIPropertyListView())
      ->setUser($viewer)
      ->setStacked(true);

    $headers = $mail->getDeliveredHeaders();
    if ($headers === null) {
      $headers = $mail->generateHeaders();
    }

    // Sort headers by name.
    $headers = isort($headers, 0);

    foreach ($headers as $header) {
      list($key, $value) = $header;
      $properties->addProperty($key, $value);
    }

    return $properties;
  }

  private function buildDeliveryProperties(PhabricatorMetaMTAMail $mail) {
    $viewer = $this->getViewer();

    $properties = id(new PHUIPropertyListView())
      ->setUser($viewer);

    $actors = $mail->getDeliveredActors();
    $reasons = null;
    if (!$actors) {
      if ($mail->getStatus() == PhabricatorMailOutboundStatus::STATUS_QUEUE) {
        $delivery = $this->renderEmptyMessage(
          pht(
            'This message has not been delivered yet, so delivery information '.
            'is not available.'));
      } else {
        $delivery = $this->renderEmptyMessage(
          pht(
            'This is an older message that predates recording delivery '.
            'information, so none is available.'));
      }
    } else {
      $actor = idx($actors, $viewer->getPHID());
      if (!$actor) {
        $delivery = phutil_tag(
          'em',
          array(),
          pht('This message was not delivered to you.'));
      } else {
        $deliverable = $actor['deliverable'];
        if ($deliverable) {
          $delivery = pht('Delivered');
        } else {
          $delivery = pht('Voided');
        }

        $reasons = id(new PHUIStatusListView());

        $reason_codes = $actor['reasons'];
        if (!$reason_codes) {
          $reason_codes = array(
            PhabricatorMetaMTAActor::REASON_NONE,
          );
        }

        $icon_yes = 'fa-check green';
        $icon_no = 'fa-times red';

        foreach ($reason_codes as $reason) {
          $target = phutil_tag(
            'strong',
            array(),
            PhabricatorMetaMTAActor::getReasonName($reason));

          if (PhabricatorMetaMTAActor::isDeliveryReason($reason)) {
            $icon = $icon_yes;
          } else {
            $icon = $icon_no;
          }

          $item = id(new PHUIStatusItemView())
            ->setIcon($icon)
            ->setTarget($target)
            ->setNote(PhabricatorMetaMTAActor::getReasonDescription($reason));

          $reasons->addItem($item);
        }
      }
    }

    $properties->addProperty(pht('Delivery'), $delivery);
    if ($reasons) {
      $properties->addProperty(pht('Reasons'), $reasons);
      $properties->addProperty(
        null,
        $this->renderEmptyMessage(
          pht(
            'Delivery reasons are listed from weakest to strongest.')));
    }

    $properties->addSectionHeader(
      pht('Routing Rules'), 'fa-paper-plane-o');

    $map = $mail->getDeliveredRoutingMap();
    $routing_detail = null;
    if ($map === null) {
      if ($mail->getStatus() == PhabricatorMailOutboundStatus::STATUS_QUEUE) {
        $routing_result = $this->renderEmptyMessage(
          pht(
            'This message has not been sent yet, so routing rules have '.
            'not been computed.'));
      } else {
        $routing_result = $this->renderEmptyMessage(
          pht(
            'This is an older message which predates routing rules.'));
      }
    } else {
      $rule = idx($map, $viewer->getPHID());
      if ($rule === null) {
        $rule = idx($map, 'default');
      }

      if ($rule === null) {
        $routing_result = $this->renderEmptyMessage(
          pht(
            'No routing rules applied when delivering this message to you.'));
      } else {
        $rule_const = $rule['rule'];
        $reason_phid = $rule['reason'];
        switch ($rule_const) {
          case PhabricatorMailRoutingRule::ROUTE_AS_NOTIFICATION:
            $routing_result = pht(
              'This message was routed as a notification because it '.
              'matched %s.',
              $viewer->renderHandle($reason_phid)->render());
            break;
          case PhabricatorMailRoutingRule::ROUTE_AS_MAIL:
            $routing_result = pht(
              'This message was routed as an email because it matched %s.',
              $viewer->renderHandle($reason_phid)->render());
            break;
          default:
            $routing_result = pht('Unknown routing rule "%s".', $rule_const);
            break;
        }
      }

      $routing_rules = $mail->getDeliveredRoutingRules();
      if ($routing_rules) {
        $rules = array();
        foreach ($routing_rules as $rule) {
          $phids = idx($rule, 'phids');
          if ($phids === null) {
            $rules[] = $rule;
          } else if (in_array($viewer->getPHID(), $phids)) {
            $rules[] = $rule;
          }
        }

        // Reorder rules by strength.
        foreach ($rules as $key => $rule) {
          $const = $rule['routingRule'];
          $phids = $rule['phids'];

          if ($phids === null) {
            $type = 'A';
          } else {
            $type = 'B';
          }

          $rules[$key]['strength'] = sprintf(
            '~%s%08d',
            $type,
            PhabricatorMailRoutingRule::getRuleStrength($const));
        }
        $rules = isort($rules, 'strength');

        $routing_detail = id(new PHUIStatusListView());
        foreach ($rules as $rule) {
          $const = $rule['routingRule'];
          $phids = $rule['phids'];

          $name = PhabricatorMailRoutingRule::getRuleName($const);

          $icon = PhabricatorMailRoutingRule::getRuleIcon($const);
          $color = PhabricatorMailRoutingRule::getRuleColor($const);

          if ($phids === null) {
            $kind = pht('Global');
          } else {
            $kind = pht('Personal');
          }

          $target = array($kind, ': ', $name);
          $target = phutil_tag('strong', array(), $target);

          $item = id(new PHUIStatusItemView())
            ->setTarget($target)
            ->setNote($viewer->renderHandle($rule['reasonPHID']))
            ->setIcon($icon, $color);

          $routing_detail->addItem($item);
        }
      }
    }

    $properties->addProperty(pht('Effective Rule'), $routing_result);

    if ($routing_detail !== null) {
      $properties->addProperty(pht('All Matching Rules'), $routing_detail);
      $properties->addProperty(
        null,
        $this->renderEmptyMessage(
          pht(
            'Matching rules are listed from weakest to strongest.')));
    }

    return $properties;
  }

  private function buildMetadataProperties(PhabricatorMetaMTAMail $mail) {
    $viewer = $this->getViewer();

    $properties = id(new PHUIPropertyListView())
      ->setUser($viewer);

    $properties->addProperty(pht('Message PHID'), $mail->getPHID());

    $details = $mail->getMessage();
    if (!strlen($details)) {
      $details = phutil_tag('em', array(), pht('None'));
    }
    $properties->addProperty(pht('Status Details'), $details);

    $actor_phid = $mail->getActorPHID();
    if ($actor_phid) {
      $actor_str = $viewer->renderHandle($actor_phid);
    } else {
      $actor_str = pht('Generated by Phabricator');
    }
    $properties->addProperty(pht('Actor'), $actor_str);

    $related_phid = $mail->getRelatedPHID();
    if ($related_phid) {
      $related = $viewer->renderHandle($mail->getRelatedPHID());
    } else {
      $related = phutil_tag('em', array(), pht('None'));
    }
    $properties->addProperty(pht('Related Object'), $related);

    return $properties;
  }

  private function renderEmptyMessage($message) {
    return phutil_tag('em', array(), $message);
  }

}
