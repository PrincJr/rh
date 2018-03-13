<?php

namespace App\Controller;

use Cake\Core\Configure;
use Cake\Datasource\ConnectionInterface;
use Cake\Datasource\ConnectionManager;
use Cake\Filesystem\File;
use Cake\Log\Log;
use Cake\Network\Session;
use Cake\ORM\TableRegistry;
use Cake\Utility\Xml;
use \DateInterval;
use \DateTime;
use \Exception;
use \PDO;

class RelatoriosController extends AppController
{
    public function initialize()
    {
        parent::initialize();
    }

    public function index()
    {
        $t_usuarios = TableRegistry::get('Usuario');
        $t_funcionarios = TableRegistry::get('Funcionario');
        $t_atestados = TableRegistry::get('Atestado');
        $t_medicos = TableRegistry::get('Medico');

        $usuarios = $t_usuarios->find('all')->count();
        $funcionarios = $t_funcionarios->find('all')->count();
        $atestados = $t_atestados->find('all')->count();
        $medicos = $t_medicos->find('all')->count();
        
        $this->set('title', 'Visão Geral dos Relatórios');
        $this->set('icon', 'library_books');
        $this->set('usuarios', $usuarios);
        $this->set('funcionarios', $funcionarios);
        $this->set('atestados', $atestados);
        $this->set('medicos', $medicos);
    }

    public function funcionariosatestados()
    {
        $datasource = Configure::read('Database.datasource');
        $connection = ConnectionManager::get($datasource);
        $link = $this->abrirBanco($connection);

        $t_tipo_funcionario = TableRegistry::get('TipoFuncionario');
        $t_empresas = TableRegistry::get('Empresa');

        $relatorio = array();
        $data = array();

        if (count($this->request->getQueryParams()) > 3)
        {
            $empresa = $this->request->query('empresa');
            $tipo_funcionario = $this->request->query('tipo_funcionario');
            $exibir = $this->request->query('exibir');
            $mostrar = $this->request->query('mostrar');

            $data['empresa'] = $empresa;
            $data['tipo_funcionario'] = $tipo_funcionario;
            $data['exibir'] = $exibir;
            $data['mostrar'] = $mostrar;

            $this->request->data = $data;
        }

        $query = $this->montarRelatorioFuncionariosAtestado($data);
        $relatorio = $link->query($query);

        if(count($data) == 0)
        {
            $data['mostrar'] = 'T';
        }

        $tipos_funcionarios = $t_tipo_funcionario->find('list', [
            'keyField' => 'id',
            'valueField' => 'descricao'
        ]);

        $empresas = $t_empresas->find('list', [
            'keyField' => 'id',
            'valueField' => 'nome'
        ]);

        $combo_exibir = [
            'T' => 'Todos',
            'A' => 'Somente os funcionários que solicitatam o atestado',
            'E' => 'Somente funcionários em estágio probatório'
        ];

        $combo_mostra = [
            'T' => 'Todos os atestados cadastrados', 
            '1' => 'Atestados cadastrados no último mês', 
            '3' => 'Atestados cadastrados nos últimos 3 meses',
            '6' => 'Atestados cadastrados nos últimos 6 meses',
            '12' => 'Atestados cadastrados no último ano'
        ];
        
        $this->set('title', 'Relatório de Funcionários por Atestado');
        $this->set('icon', 'assignment_ind');
        $this->set('tipos_funcionarios', $tipos_funcionarios);
        $this->set('empresas', $empresas);
        $this->set('combo_mostra', $combo_mostra);
        $this->set('combo_exibir', $combo_exibir);
        $this->set('data', $data);
        $this->set('relatorio', $relatorio);
    }

    public function imprimirfuncionariosatestados()
    {
        $datasource = Configure::read('Database.datasource');
        $connection = ConnectionManager::get($datasource);
        $link = $this->abrirBanco($connection);

        $relatorio = array();
        $data = array();

        if (count($this->request->getQueryParams()) > 3)
        {
            $empresa = $this->request->query('empresa');
            $tipo_funcionario = $this->request->query('tipo_funcionario');
            $exibir = $this->request->query('exibir');
            $mostrar = $this->request->query('mostrar');

            $data['empresa'] = $empresa;
            $data['tipo_funcionario'] = $tipo_funcionario;
            $data['exibir'] = $exibir;
            $data['mostrar'] = $mostrar;
        }

        $query = $this->montarRelatorioFuncionariosAtestado($data);
        $relatorio = $link->query($query);

        $this->viewBuilder()->layout('print');

        $this->set('title', 'Relatório de Funcionários por Atestado');
        $this->set('relatorio', $relatorio);
    }

    public function atestadosfuncionario()
    {
        $t_atestados = TableRegistry::get('Atestado');
        $t_funcionarios = TableRegistry::get('Funcionario');
        
        $idFuncionario = $this->request->query('idFuncionario');
        $mostrar = $this->request->query('periodo');

        $funcionario = $t_funcionarios->get($idFuncionario);
        $atestados = null;

        $opcoes_subtitulos = [
            'T' => 'Atestados emitidos para o funcionário ' . $funcionario->nome, 
            '1' => 'Atestados emitidos para o funcionário ' . $funcionario->nome . ' nos últimos 30 dias',  
            '3' => 'Atestados emitidos para o funcionário ' . $funcionario->nome . ' nos últimos 3 meses',  
            '6' => 'Atestados emitidos para o funcionário ' . $funcionario->nome . ' nos últimos 6 meses',
            '12' => 'Atestados emitidos para o funcionário ' . $funcionario->nome . ' no último ano',
        ];

        if($mostrar == 'T')
        {
            $atestados = $t_atestados->find('all', [
                'contain' => ['Medico'],
                'conditions' => [
                    'funcionario' => $idFuncionario
                ],
                'order' => [
                    'afastamento' => 'DESC'
                ]
            ]);
        }
        else
        {
            $data_final = new DateTime();
            $data_inicial = $this->calcularDataInicial($mostrar);

            $atestados = $t_atestados->find('all', [
                'contain' => ['Medico'],
                'conditions' => [
                    'funcionario' => $idFuncionario,
                    'emissao >=' => $data_inicial->format("Y-m-d"),
                    'emissao <=' => $data_final->format("Y-m-d")
                ],
                'order' => [
                    'afastamento' => 'DESC'
                ]
            ]);
        }

        $quantidade = $atestados->count();

        $this->set('title', 'Relatório de Funcionários por Atestado');
        $this->set('subtitle', $opcoes_subtitulos[$mostrar]);
        $this->set('icon', 'assignment_ind');
        $this->set('funcionario', $funcionario);
        $this->set('atestados', $atestados);
    }

    public function atestadodetalhe(int $id)
    {
        $title = 'Consulta de Dados do Atestado';
        $icon = 'local_hospital';

        $t_atestado = TableRegistry::get('Atestado');
        
        $atestado = $t_atestado->get($id, ['contain' => ['Funcionario', 'Medico']]);

        $this->set('title', $title);
        $this->set('icon', $icon);
        $this->set('id', $id);
        $this->set('atestado', $atestado);
    }

    protected function montarRelatorioFuncionariosAtestado(array $data)
    {
        $query = "";

        if (count($data) > 0)
        {
            $empresa = $data['empresa'];
            $tipo_funcionario = $data['tipo_funcionario'];
            $exibir = $data['exibir'];
            $mostrar = $data['mostrar'];

            if($mostrar == 'T')
            {
                $query = "select f.id,
                            f.matricula matricula,
                            f.nome nome,
                            f.cargo cargo,
                            tf.descricao tipo,
                            e.nome empresa,
                            f.probatorio probatorio,
                            (select count(*)
                            from atestado a
                            where a.funcionario = f.id) quantidade
                    from funcionario f
                    inner join empresa e
                        on f.empresa = e.id
                    inner join tipo_funcionario tf
                        on f.tipo = tf.id
                    where f.ativo = 1 ";
            }
            else
            {
                $data_final = new DateTime();
                $data_inicial = $this->calcularDataInicial($mostrar);

                $query = "select f.id,
                            f.matricula matricula,
                            f.nome nome,
                            f.cargo cargo,
                            tf.descricao tipo,
                            e.nome empresa,
                            f.probatorio probatorio,
                            (select count(*)
                            from atestado a
                            where a.funcionario = f.id
                                and a.emissao between " . $data_inicial->format("Y-m-d") . " and " . $data_final->format("Y-m-d") . ") quantidade
                    from funcionario f
                    inner join empresa e
                        on f.empresa = e.id
                    inner join tipo_funcionario tf
                        on f.tipo = tf.id
                    where f.ativo = 1 ";
            }
            
            if($empresa != "")
            {
                $query = $query . "and e.id = " . $empresa . " ";
            }
            
            if($tipo_funcionario != "")
            {
                $query = $query . "and tf.id = " . $tipo_funcionario . " ";
            }

            if($exibir == 'A')
            {
                $query = $query . "having quantidade > 0 ";
            }
            elseif($exibir == 'E')
            {
                $query = $query . "and f.probatorio = 1 ";
            }
            
            $query = $query . "order by quantidade desc, f.nome asc;";
        }
        else
        {
            $query = "select f.id,
                            f.matricula matricula,
                            f.nome nome,
                            f.cargo cargo,
                            tf.descricao tipo,
                            e.nome empresa,
                            f.probatorio probatorio,
                            (select count(*)
                            from atestado a
                            where a.funcionario = f.id) quantidade
                    from funcionario f
                    inner join empresa e
                        on f.empresa = e.id
                    inner join tipo_funcionario tf
                        on f.tipo = tf.id
                    where f.ativo = 1
                    order by quantidade desc, f.nome asc;";
        }

        return $query;
    }

    private function abrirBanco(ConnectionInterface $connection) 
    {
        $config = $connection->config();
        $username = $config['username'];
        $password = $config['password'];

        $dsn = "mysql:host=" . $config['host'] . "; dbname=" . $config['database'];
        $link = new PDO($dsn, $username, $password);
        $link->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        return $link;
    } 
    
    private function calcularDataInicial(string $mostrar)
    {
        switch($mostrar)
        {
            case "1":
                $key_sub = "P30D";
                break;

            case "3":
                $key_sub = "P3M";
                break;
            
            case "6":
                $key_sub = "P6M";
                break;
            
            case "12":
                $key_sub = "P1Y";
                break;
        }
        
        $data_inicial = new DateTime();
        $data_inicial->sub(new DateInterval($key_sub));

        return $data_inicial;
    }
}