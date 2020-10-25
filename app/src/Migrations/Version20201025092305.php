<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20201025092305 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->abortIf($this->connection->getDatabasePlatform()->getName() !== 'mysql', 'Migration can only be executed safely on \'mysql\'.');

        $this->addSql("
            UPDATE example SET id_hash = '9v1' WHERE id=1;
            UPDATE example SET id_hash = '9o0' WHERE id=2;
            UPDATE example SET id_hash = 'q7v' WHERE id=3;
            UPDATE example SET id_hash = 'qxk' WHERE id=4;
            UPDATE example SET id_hash = 'q2n' WHERE id=5;
            UPDATE example SET id_hash = 'j0l' WHERE id=6;
            UPDATE example SET id_hash = 'j53' WHERE id=7;
            UPDATE example SET id_hash = '968' WHERE id=8;
            UPDATE example SET id_hash = 'q4r' WHERE id=9;
            UPDATE example SET id_hash = '9mn' WHERE id=10;
            UPDATE example SET id_hash = '9r0' WHERE id=11;
            UPDATE example SET id_hash = 'qdn' WHERE id=12;
            UPDATE example SET id_hash = 'ql1' WHERE id=13;
            UPDATE example SET id_hash = 'q8n' WHERE id=14;
            UPDATE example SET id_hash = 'qyk' WHERE id=15;
            UPDATE example SET id_hash = '917' WHERE id=16;
            UPDATE example SET id_hash = 'jpn' WHERE id=17;
            UPDATE example SET id_hash = 'j30' WHERE id=18;
            UPDATE example SET id_hash = 'jk7' WHERE id=19;
            UPDATE example SET id_hash = 'qzx' WHERE id=20;
            UPDATE example SET id_hash = '9w2' WHERE id=21;
            UPDATE example SET id_hash = 'jex' WHERE id=22;
            UPDATE example SET id_hash = 'jge' WHERE id=23;
            UPDATE example SET id_hash = 'nkw' WHERE id=24;
            UPDATE example SET id_hash = 'v11' WHERE id=25;
            UPDATE example SET id_hash = 'ok0' WHERE id=26;
            UPDATE example SET id_hash = '7wv' WHERE id=27;
            UPDATE example SET id_hash = 'xwk' WHERE id=28;
            UPDATE example SET id_hash = '2rn' WHERE id=29;
            UPDATE example SET id_hash = '0yl' WHERE id=30;
            UPDATE example SET id_hash = '5g3' WHERE id=31;
            UPDATE example SET id_hash = '608' WHERE id=32;
            UPDATE example SET id_hash = '4kr' WHERE id=33;
            UPDATE example SET id_hash = 'm3n' WHERE id=34;
            UPDATE example SET id_hash = 'r50' WHERE id=35;
            UPDATE example SET id_hash = 'dln' WHERE id=36;
            UPDATE example SET id_hash = 'l01' WHERE id=37;
            UPDATE example SET id_hash = '8gn' WHERE id=38;
            UPDATE example SET id_hash = 'ygk' WHERE id=39;
            UPDATE example SET id_hash = '1y7' WHERE id=40;
            UPDATE example SET id_hash = 'p2n' WHERE id=41;
            UPDATE example SET id_hash = '370' WHERE id=42;
            UPDATE example SET id_hash = 'k17' WHERE id=43;
            UPDATE example SET id_hash = 'zwx' WHERE id=44;
            UPDATE example SET id_hash = 'ww2' WHERE id=45;
            UPDATE example SET id_hash = 'e4x' WHERE id=46;
            UPDATE example SET id_hash = 'gwe' WHERE id=47;
            UPDATE example SET id_hash = 'n2w' WHERE id=48;
            UPDATE example SET id_hash = 'vr1' WHERE id=49;
            UPDATE example SET id_hash = 'o00' WHERE id=50;
            UPDATE example SET id_hash = '78v' WHERE id=51;
            UPDATE example SET id_hash = 'x3k' WHERE id=52;
            UPDATE example SET id_hash = '25n' WHERE id=53;
            UPDATE example SET id_hash = '02l' WHERE id=54;
            UPDATE example SET id_hash = '5k3' WHERE id=55;
            UPDATE example SET id_hash = '638' WHERE id=56;
            UPDATE example SET id_hash = '4lr' WHERE id=57;
            UPDATE example SET id_hash = 'mwn' WHERE id=58;
            UPDATE example SET id_hash = 'rk0' WHERE id=59;
            UPDATE example SET id_hash = 'dpn' WHERE id=60;
            UPDATE example SET id_hash = 'lp1' WHERE id=61;
            UPDATE example SET id_hash = '88n' WHERE id=62;
            UPDATE example SET id_hash = 'ywk' WHERE id=63;
            UPDATE example SET id_hash = '1g7' WHERE id=64;
            UPDATE example SET id_hash = 'p0n' WHERE id=65;
            UPDATE example SET id_hash = '360' WHERE id=66;
            UPDATE example SET id_hash = 'kr7' WHERE id=67;
            UPDATE example SET id_hash = 'z5x' WHERE id=68;
            UPDATE example SET id_hash = 'w02' WHERE id=69;
            UPDATE example SET id_hash = 'e2x' WHERE id=70;
            UPDATE example SET id_hash = 'gle' WHERE id=71;
            UPDATE example SET id_hash = 'n4w' WHERE id=72;
            UPDATE example SET id_hash = 'v31' WHERE id=73;
            UPDATE example SET id_hash = 'ox0' WHERE id=74;
            UPDATE example SET id_hash = '7vv' WHERE id=75;
            UPDATE example SET id_hash = 'x5k' WHERE id=76;
            UPDATE example SET id_hash = '2on' WHERE id=77;
            UPDATE example SET id_hash = '0nl' WHERE id=78;
            UPDATE example SET id_hash = '5p3' WHERE id=79;
            UPDATE example SET id_hash = '6n8' WHERE id=80;
            UPDATE example SET id_hash = '4xr' WHERE id=81;
            UPDATE example SET id_hash = 'm5n' WHERE id=82;
            UPDATE example SET id_hash = 'rg0' WHERE id=83;
            UPDATE example SET id_hash = 'dxn' WHERE id=84;
            UPDATE example SET id_hash = 'l51' WHERE id=85;
            UPDATE example SET id_hash = '8on' WHERE id=86;
            UPDATE example SET id_hash = 'y6k' WHERE id=87;
            UPDATE example SET id_hash = '1v7' WHERE id=88;
            UPDATE example SET id_hash = 'p5n' WHERE id=89;
            UPDATE example SET id_hash = '3k0' WHERE id=90;
            UPDATE example SET id_hash = 'k27' WHERE id=91;
            UPDATE example SET id_hash = 'zkx' WHERE id=92;
            UPDATE example SET id_hash = 'w32' WHERE id=93;
            UPDATE example SET id_hash = 'erx' WHERE id=94;
            UPDATE example SET id_hash = 'gve' WHERE id=95;
            UPDATE example SET id_hash = 'n7w' WHERE id=96;
            UPDATE example SET id_hash = 'v21' WHERE id=97;
            UPDATE example SET id_hash = 'og0' WHERE id=98;
            UPDATE example SET id_hash = '72v' WHERE id=99;
            UPDATE example SET id_hash = 'n77' WHERE id=100;
            UPDATE example SET id_hash = 'v2l' WHERE id=101;
            UPDATE example SET id_hash = 'og2' WHERE id=102;
            UPDATE example SET id_hash = '72k' WHERE id=103;
            UPDATE example SET id_hash = 'xkx' WHERE id=104;
            UPDATE example SET id_hash = '2ew' WHERE id=105;
            UPDATE example SET id_hash = '0k4' WHERE id=106;
            UPDATE example SET id_hash = '5we' WHERE id=107;
            UPDATE example SET id_hash = '6wy' WHERE id=108;
            UPDATE example SET id_hash = '4e1' WHERE id=109;
            UPDATE example SET id_hash = 'moz' WHERE id=110;
            UPDATE example SET id_hash = 'rle' WHERE id=111;
            UPDATE example SET id_hash = 'dm0' WHERE id=112;
            UPDATE example SET id_hash = 'lvl' WHERE id=113;
            UPDATE example SET id_hash = '8wr' WHERE id=114;
            UPDATE example SET id_hash = 'ylp' WHERE id=115;
            UPDATE example SET id_hash = '1wr' WHERE id=116;
            UPDATE example SET id_hash = 'p3k' WHERE id=117;
            UPDATE example SET id_hash = '3wz' WHERE id=118;
            UPDATE example SET id_hash = 'kn5' WHERE id=119;
            UPDATE example SET id_hash = 'zo4' WHERE id=120;
            UPDATE example SET id_hash = 'w1w' WHERE id=121;
            UPDATE example SET id_hash = 'ep2' WHERE id=122;
            UPDATE example SET id_hash = 'grv' WHERE id=123;
            UPDATE example SET id_hash = 'n17' WHERE id=124;
            UPDATE example SET id_hash = 'vll' WHERE id=125;
            UPDATE example SET id_hash = 'op2' WHERE id=126;
            UPDATE example SET id_hash = '7nk' WHERE id=127;
            UPDATE example SET id_hash = 'xnx' WHERE id=128;
            UPDATE example SET id_hash = '2nw' WHERE id=129;
            UPDATE example SET id_hash = '054' WHERE id=130;
            UPDATE example SET id_hash = '57e' WHERE id=131;
            UPDATE example SET id_hash = '6xy' WHERE id=132;
            UPDATE example SET id_hash = '4o1' WHERE id=133;
            UPDATE example SET id_hash = 'mxz' WHERE id=134;
            UPDATE example SET id_hash = 'rze' WHERE id=135;
            UPDATE example SET id_hash = 'dw0' WHERE id=136;
            UPDATE example SET id_hash = 'l7l' WHERE id=137;
            UPDATE example SET id_hash = '8vr' WHERE id=138;
            UPDATE example SET id_hash = 'yvp' WHERE id=139;
            UPDATE example SET id_hash = '1zr' WHERE id=140;
            UPDATE example SET id_hash = 'p1k' WHERE id=141;
            UPDATE example SET id_hash = '3vz' WHERE id=142;
            UPDATE example SET id_hash = 'ko5' WHERE id=143;
            UPDATE example SET id_hash = 'zz4' WHERE id=144;
            UPDATE example SET id_hash = 'wzw' WHERE id=145;
            UPDATE example SET id_hash = 'ew2' WHERE id=146;
            UPDATE example SET id_hash = 'gpv' WHERE id=147;
            UPDATE example SET id_hash = 'nx7' WHERE id=148;
            UPDATE example SET id_hash = 'vzl' WHERE id=149;
            UPDATE example SET id_hash = 'o22' WHERE id=150;
            UPDATE example SET id_hash = '75k' WHERE id=151;
            UPDATE example SET id_hash = 'xzx' WHERE id=152;
            UPDATE example SET id_hash = '2yw' WHERE id=153;
            UPDATE example SET id_hash = '0l4' WHERE id=154;
            UPDATE example SET id_hash = '5le' WHERE id=155;
            UPDATE example SET id_hash = '6ky' WHERE id=156;
            UPDATE example SET id_hash = '431' WHERE id=157;
            UPDATE example SET id_hash = 'm7z' WHERE id=158;
            UPDATE example SET id_hash = 'r4e' WHERE id=159;
            UPDATE example SET id_hash = 'd60' WHERE id=160;
            UPDATE example SET id_hash = 'lol' WHERE id=161;
            UPDATE example SET id_hash = '8kr' WHERE id=162;
            UPDATE example SET id_hash = 'yzp' WHERE id=163;
            UPDATE example SET id_hash = '1lr' WHERE id=164;
            UPDATE example SET id_hash = 'p4k' WHERE id=165;
            UPDATE example SET id_hash = '3ez' WHERE id=166;
            UPDATE example SET id_hash = 'kv5' WHERE id=167;
            UPDATE example SET id_hash = 'zn4' WHERE id=168;
            UPDATE example SET id_hash = 'wxw' WHERE id=169;
            UPDATE example SET id_hash = 'en2' WHERE id=170;
            UPDATE example SET id_hash = 'gov' WHERE id=171;
            UPDATE example SET id_hash = 'n67' WHERE id=172;
            UPDATE example SET id_hash = 'v7l' WHERE id=173;
            UPDATE example SET id_hash = 'o42' WHERE id=174;
            UPDATE example SET id_hash = '7kk' WHERE id=175;
            UPDATE example SET id_hash = 'x2x' WHERE id=176;
            UPDATE example SET id_hash = '23w' WHERE id=177;
            UPDATE example SET id_hash = '0w4' WHERE id=178;
            UPDATE example SET id_hash = '53e' WHERE id=179;
            UPDATE example SET id_hash = '6ly' WHERE id=180;
            UPDATE example SET id_hash = '4p1' WHERE id=181;
            UPDATE example SET id_hash = 'm6z' WHERE id=182;
            UPDATE example SET id_hash = 'r6e' WHERE id=183;
            UPDATE example SET id_hash = 'd40' WHERE id=184;
            UPDATE example SET id_hash = 'ldl' WHERE id=185;
            UPDATE example SET id_hash = '86r' WHERE id=186;
            UPDATE example SET id_hash = 'y0p' WHERE id=187;
            UPDATE example SET id_hash = '1er' WHERE id=188;
            UPDATE example SET id_hash = 'pwk' WHERE id=189;
            UPDATE example SET id_hash = '33z' WHERE id=190;
            UPDATE example SET id_hash = 'k05' WHERE id=191;
            UPDATE example SET id_hash = 'z04' WHERE id=192;
            UPDATE example SET id_hash = 'w7w' WHERE id=193;
            UPDATE example SET id_hash = 'ed2' WHERE id=194;
            UPDATE example SET id_hash = 'gdv' WHERE id=195;
            UPDATE example SET id_hash = 'n37' WHERE id=196;
            UPDATE example SET id_hash = 'vol' WHERE id=197;
            UPDATE example SET id_hash = 'ow2' WHERE id=198;
            UPDATE example SET id_hash = '7dk' WHERE id=199;
            UPDATE example SET id_hash = 'n33' WHERE id=200;
            UPDATE example SET id_hash = 'vod' WHERE id=201;
            UPDATE example SET id_hash = 'owo' WHERE id=202;
            UPDATE example SET id_hash = '7de' WHERE id=203;
            UPDATE example SET id_hash = 'xx8' WHERE id=204;
            UPDATE example SET id_hash = '2p1' WHERE id=205;
            UPDATE example SET id_hash = '0e0' WHERE id=206;
            UPDATE example SET id_hash = '5dv' WHERE id=207;
        ");
    }

    public function down(Schema $schema): void
    {
    }
}
