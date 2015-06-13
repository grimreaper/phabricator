<?php

final class PasteEditConduitAPIMethod extends PasteConduitAPIMethod {

  public function getAPIMethodName() {
    return 'paste.edit';
  }

  public function getMethodDescription() {
    return 'Edit a paste.';
  }

  public function defineParamTypes() {
    return array(
      'content'   => 'required string',
      'paste_id'  => 'required id',
      'title'     => 'optional string',
      'language'  => 'optional string',
    );
  }

  public function defineReturnType() {
    return 'nonempty dict';
  }

  public function defineErrorTypes() {
    return array(
      'ERR-NO-PASTE' => 'Paste may not be empty.',
      'ERR-NO-ACCESS' => 'You do not have permission to edit this paste.',
      'ERR-BAD-PASTE' => 'No such paste exists',
    );
  }

  protected function execute(ConduitAPIRequest $request) {
    $content  = $request->getValue('content');
    $title    = $request->getValue('title');
    $language = $request->getValue('language');
    $paste_id = $request->getValue('paste_id');

    if (!strlen($content)) {
      throw new ConduitException('ERR-NO-PASTE');
    }

    $viewer = $request->getUser();

    try {
        $paste = id(new PhabricatorPasteQuery())
            ->setViewer($request->getUser())
            ->requireCapabilities(
                array(
                    PhabricatorPolicyCapability::CAN_VIEW,
                    PhabricatorPolicyCapability::CAN_EDIT,
                ))
            ->withIDs(array($paste_id))
            ->needRawContent(true)
            ->executeOne();
    } catch (PhabricatorPolicyException $ex) {
        throw new ConduitException('ERR-NO-ACCESS');
    }

    if (!$paste) {
        throw new ConduitException('ERR-BAD-PASTE');
    }

    $xactions = array();

    if ($content != $paste->getRawContent()) {
        $file = PhabricatorPasteEditor::initializeFileForPaste(
          $viewer,
          $title,
          $content);

        $xactions[] = id(new PhabricatorPasteTransaction())
          ->setTransactionType(PhabricatorPasteTransaction::TYPE_CONTENT)
          ->setNewValue($file->getPHID());
    }

    if ($title && $title != $paste->getTitle()) {
        $xactions[] = id(new PhabricatorPasteTransaction())
          ->setTransactionType(PhabricatorPasteTransaction::TYPE_TITLE)
          ->setNewValue($title);
    }

    if ($language && $language != $paste->getLanguage()) {
        $xactions[] = id(new PhabricatorPasteTransaction())
          ->setTransactionType(PhabricatorPasteTransaction::TYPE_LANGUAGE)
          ->setNewValue($language);
    }

    if (count($xactions) > 0) {
        $editor = id(new PhabricatorPasteEditor())
          ->setActor($viewer)
          ->setContentSourceFromConduitRequest($request);

        $xactions = $editor->applyTransactions($paste, $xactions);

        $paste->attachRawContent($content);
    }
    return $this->buildPasteInfoDictionary($paste);
  }

}
