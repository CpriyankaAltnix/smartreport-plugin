<?php

namespace GlpiPlugin\Smartreport;

use CommonGLPI;
use Html;
use Session;
// use GlpiPlugin\Smartreport\Reportdefination;

class Profile extends \Profile
{
    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if (
            $item instanceof \Profile
            && $item->getField('id')
        ) {
            return self::createTabEntry(__('Smart Report', 'smartreport'));
        }

        return '';
    }

    // public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    // {
    //     if ($item instanceof \Profile) {
    //         $profile = new self();
    //         $profile->showFormExample($item->getID());
    //     }
    //     return true;
    // }

    // public function showFormExample(int $profiles_id): void
    // {
    //     if (!$this->can($profiles_id, READ)) {
    //         return;
    //     }

    //     echo "<div class='spaced'>";

    //     $can_edit = Session::haveRight(self::$rightname, UPDATE);
    //     if ($can_edit) {
    //         echo "<form method='post' action='" . htmlspecialchars(self::getFormURL()) . "'>";
    //     }

    //     $matrix_options = [
    //         'canedit' => $can_edit,
    //     ];
    //     $rights = [
    //         [
    //             'label'    => __('Smart Report', 'smartreport'),
    //             'itemtype' => Reportdefination::class,
    //             'field'    => Reportdefination::$rightname,
    //             'rights'   => [
    //                 READ                                        => __('Read'),
    //                 CREATE                                      => __('Create'),
    //                 UPDATE                                      => __('Update'),
    //                 Reportdefination::EXECUTE  => __('Execute', 'smartreport'),
    //             ],
    //         ],
    //     ];
    //     $matrix_options['title'] = self::getTypeName(1);
    //     $this->displayRightsChoiceMatrix($rights, $matrix_options);

    //     if ($can_edit) {
    //         echo "<div class='text-center'>";
    //         echo Html::hidden('id', ['value' => $profiles_id]);
    //         echo Html::submit(_sx('button', 'Save'), ['name' => 'update']);
    //         echo "</div>\n";
    //         Html::closeForm();
    //     }
    //     echo '</div>';
    // }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        if (!($item instanceof \Profile)) {
            return true;
        }

        $profile  = new \Profile();
        $profile->getFromDB($item->getID());
        $can_edit = Session::haveRight(self::$rightname, UPDATE);

        echo "<div class='spaced'>";

        if ($can_edit) {
            echo "<form method='post' action='" . $profile->getFormURL() . "'>";
        }

        $rights = [
            [
                'label'    => __('Smart Report', 'smartreport'),
                'itemtype' => Reportdefination::class,
                'field'    => Reportdefination::$rightname,
                'rights'   => [
                    READ                                        => __('Read'),
                    CREATE                                      => __('Create'),
                    UPDATE                                      => __('Update'),
                    Reportdefination::EXECUTE  => __('Execute', 'smartreport'),
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