<?php

class PluginSmartreportProfile extends Profile
{
    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if (
            $item instanceof Profile
            && $item->getField('id')
        ) {
            return self::createTabEntry(__('Smart Report', 'smartreport'));
        }

        return '';
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        if (!($item instanceof Profile)) {
            return true;
        }

        $profile  = new Profile();
        $profile->getFromDB($item->getID());
        $can_edit = Session::haveRight(self::$rightname, UPDATE);

        echo "<div class='spaced'>";

        if ($can_edit) {
            echo "<form method='post' action='" . $profile->getFormURL() . "'>";
        }

        $rights = [
            [
                'label'    => __('Smart Report', 'smartreport'),
                'itemtype' => PluginSmartreportReportdefination::class,
                'field'    => PluginSmartreportReportdefination::$rightname,
                'rights'   => [
                    READ                                        => __('Read'),
                    CREATE                                      => __('Create'),
                    UPDATE                                      => __('Update'),
                    PluginSmartreportReportdefination::EXECUTE  => __('Execute', 'smartreport'),
                ],
            ],
        ];

        $profile->displayRightsChoiceMatrix($rights, [
            'canedit'       => Session::haveRightsOr('profile', [UPDATE]),
            'default_class' => 'tab_bg_2',
        ]);

        if ($can_edit) {
            echo "<div class='text-center'>";
            echo Html::hidden('id', ['value' => $item->getID()]);
            echo Html::submit(_sx('button', 'Save'), ['name' => 'update']);
            echo "</div>\n";
            Html::closeForm();
        }

        echo '</div>';
        return true;
    }
}
